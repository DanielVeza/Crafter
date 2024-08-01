<?php

declare(strict_types=1);

namespace Drupal\crafter\Command;

use Drupal\bca\Attribute\Bundle;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ExtensionDiscovery;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Field\FieldTypePluginManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\crafter\CrafterPrinter;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use DrupalCodeBuilder\Generator\PHPClassFile;
use DrupalCodeGenerator\Asset\AssetCollection;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\InputOutput\Interviewer;
use DrupalCodeGenerator\InputOutput\IOAwareTrait;
use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\Literal;
use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PhpNamespace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

// phpcs:disable Drupal.Commenting.ClassComment.Missing
#[AsCommand(
  name: 'crafter:content-type',
  description: '',
  aliases: ['c:ct'],
)]
final class ExampleCommand extends Command {

  use IOAwareTrait;

  protected string $entityTypeId = 'node';

  /**
   * Constructs a Di2Command object.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly FieldTypePluginManagerInterface $fieldTypePluginManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    // @todo Place your code here.

    if (!$this->moduleHandler->moduleExists('bca')) {
      $output->writeln('<error>Module bca is not enabled.</error>');
      return self::FAILURE;
    }
    $interviewer = $this->getHelper('question');
    $question = new Question('Content type name:');
    $bundle = $interviewer->ask($input, $output, $question);
    $bundleMachineName = strtolower(str_replace([' ', ''], '_', $bundle));
    $descriptionQuestion = new Question('Description:');
    $description = $interviewer->ask($input, $output, $descriptionQuestion);
    $commonFieldsQuestion = new ChoiceQuestion('Common fields (Comma seperated):', $this->reusableFieldOptions());
    $commonFieldsQuestion->setMultiselect(TRUE);
    $commonsFields = $interviewer->ask($input, $output, $commonFieldsQuestion);
    $contentType = NodeType::create([
      'type' => $bundleMachineName,
      'description' => $description,
      'name' => $bundle,
    ])->save();

    $this->addCommonFields($commonsFields, $bundleMachineName);

    $output->writeln(sprintf('<info>%s content type created.</info>', $bundle));

    $className = ucwords($bundle);
    $className = str_replace(' ', '', $className);
    $root = \Drupal::root();
    $nameSpace = new PhpNamespace('Drupal\\' . $bundleMachineName . '\\Entity');
    $nameSpace->addUse(Node::class);
    $nameSpace->addUse(Bundle::class);
    $class = new ClassType($className, $nameSpace);
    $class->addAttribute(Bundle::class, [
      'entityType' => 'node',
      'bundle' => new Literal('self::BUNDLE'),
    ]);
    $class
      ->setExtends(Node::class)
      ->addConstant('BUNDLE', $bundleMachineName);
    $this->generateGetSet($class, $commonsFields);
    $printer = new CrafterPrinter();
    $directory = sprintf('%s/modules/custom/%s/src/Entity', $root, $bundleMachineName);
    $destination = sprintf('%s/%s.php', $directory, $className);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $this->fileSystem->saveData($printer->printClass($class, $nameSpace), $destination);

    $output->writeln('<info>Bundle class created</info>');

    $directory = sprintf('%s/modules/custom/%s/tests/src/Functional', $root, $bundleMachineName);
    $this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    $destination = sprintf('%s/%sTest.php', $directory, $className);
    $nameSpace = new PhpNamespace('Drupal\\tests\\' . $bundleMachineName . '\\Functional');
    $nameSpace->addUse(sprintf('Drupal\\%s\\Entity\\%s', $bundleMachineName, $className));
    $class = new ClassType($className . 'Test', $nameSpace);
    $class
      ->setExtends('weitzman\\DrupalTestTraits\\ExistingSiteBase');
    $this->generateTest($class, $commonsFields, $className);
    $this->fileSystem->saveData($printer->printClass($class, $nameSpace), $destination);
    $output->writeln('<info>Test class created</info>');
    return self::SUCCESS;
  }

  private function reusableFieldOptions(): array {
    $options = [];
    // Load the field_storages and build the list of options.
    $field_types = $this->fieldTypePluginManager->getDefinitions();
    foreach ($this->entityFieldManager->getFieldStorageDefinitions('node') as $field_name => $field_storage) {
      // Do not show:
      // - non-configurable field storages,
      // - locked field storages,
      // - field storages that should not be added via user interface,
      // - field storages that already have a field in the bundle.
      // @todo check if field has been selected already and filter out.
      $field_type = $field_storage->getType();
      if ($field_storage instanceof FieldStorageConfigInterface
        && !$field_storage->isLocked()
        && empty($field_types[$field_type]['no_ui'])) {

        $options[] = $field_name;
      }
    }
    return $options;
  }

  /**
   * Get default options from an existing field and bundle.
   *
   * Take from FieldStorageReuseForm.
   *
   * @param string $field_name
   *   The machine name of the field.
   *
   * @return array
   *   An array of settings with keys 'field_config', 'entity_form_display', and
   *   'entity_view_display' if these are defined for an existing field
   *   instance. If the field is not defined for the specified bundle (or for
   *   any bundle if $existing_bundle is omitted) then return an empty array.
   */
  protected function getExistingFieldDefaults(string $field_name): array {
    $default_options = [];
    $field_map = $this->entityFieldManager->getFieldMap();

    if (empty($field_map[$this->entityTypeId][$field_name]['bundles'])) {
      return [];
    }
    $bundles = $field_map[$this->entityTypeId][$field_name]['bundles'];

    // Sort bundles to ensure deterministic behavior.
    sort($bundles);
    $existing_bundle = reset($bundles);

    // Copy field configuration.
    $existing_field = $this->entityFieldManager->getFieldDefinitions($this->entityTypeId, $existing_bundle)[$field_name];
    $default_options['field_config'] = [
      'description' => $existing_field->getDescription(),
      'settings' => $existing_field->getSettings(),
      'required' => $existing_field->isRequired(),
      'default_value' => $existing_field->getDefaultValueLiteral(),
      'default_value_callback' => $existing_field->getDefaultValueCallback(),
    ];

    // Copy form and view mode configuration.
    $properties = [
      'targetEntityType' => $this->entityTypeId,
      'bundle' => $existing_bundle,
    ];
    /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $existing_forms */
    $existing_forms = $this->entityTypeManager->getStorage('entity_form_display')->loadByProperties($properties);
    foreach ($existing_forms as $form) {
      if ($settings = $form->getComponent($field_name)) {
        $default_options['entity_form_display'][$form->getMode()] = $settings;
      }
    }
    /** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $existing_views */
    $existing_views = $this->entityTypeManager->getStorage('entity_view_display')->loadByProperties($properties);
    foreach ($existing_views as $view) {
      if ($settings = $view->getComponent($field_name)) {
        $default_options['entity_view_display'][$view->getMode()] = $settings;
      }
    }

    return $default_options;
  }

  private function addCommonFields(array $commonFields, string $bundleMachineName): void {
    foreach ($commonFields as $commonField) {
      $default_options = $this->getExistingFieldDefaults($commonField);
      $fields = $this->entityTypeManager->getStorage('field_config')->getQuery()
        ->accessCheck()
        ->condition('entity_type', 'node')
        ->condition('field_name', $commonField)
        ->execute();
      $field = $fields ? $this->entityTypeManager->getStorage('field_config')->load(reset($fields)) : NULL;
      // Have a default label in case a field storage doesn't have any fields.
      $existing_storage_label = $field ? $field->label() : $commonField;
      $field = $this->entityTypeManager->getStorage('field_config')->create([
        ...$default_options['field_config'] ?? [],
        'field_name' => $commonField,
        'entity_type' => 'node',
        'bundle' => $bundleMachineName,
        'label' => $existing_storage_label,
        // Field translatability should be explicitly enabled by the users.
        'translatable' => FALSE,
      ]);
      $field->save();
    }
  }

  private function generateGetSet(ClassType $class, array $fields): void {
    $nodeFields = $this->entityFieldManager->getFieldStorageDefinitions('node');
    foreach ($fields as $field) {
      //$this->entityTypeManager->getStorage('field_config')->load($field);
      /* @var FieldStorageConfigInterface $nodeField */
      $nodeField = $nodeFields[$field];
      if (!$nodeField) {
        continue;
      }
      $fieldType = $nodeField->getType();
      $formattedField = str_replace('_', ' ', $field);
      $formattedField = ucwords($formattedField);
      $formattedField = str_replace(' ', '', $formattedField);
      match($fieldType) {
        'string', 'text', 'text_long', 'string_long' => $this->stringGetSet($class, $field, $formattedField),
        //'integer' => $this->integerGetSet($class, $field),
        //'boolean' => $this->booleanGetSet($class, $field),
        //default => $this->stringGetSet($class, $field),
        default => NULL,
      };
    }
  }

  private function stringGetSet($class, $field, $formattedField) {
    $class->addMethod('get' . $formattedField)
      ->setPublic()
      ->setReturnType('?string')
      ->setBody(sprintf('return $this->get(\'%s\')->value;', $field));
    $set = $class->addMethod('set' . $formattedField)
      ->setPublic()
      ->setReturnType('static')
      ->setBody(sprintf('return $this->set(\'%s\', $value);', $field));
    $set->addParameter('value')
      ->setType('string');
  }

  private function generateTest(ClassType $class, array $fields, $bundle): void {
    $nodeFields = $this->entityFieldManager->getFieldStorageDefinitions('node');
    $class->addMethod('createEntity')
      ->setPublic()
      ->setBody(sprintf('return %s::create();', $bundle))
      ->setReturnType($bundle);

    foreach ($fields as $field) {
      /* @var FieldStorageConfigInterface $nodeField */
      $nodeField = $nodeFields[$field];
      if (!$nodeField) {
        continue;
      }
      $fieldType = $nodeField->getType();
      $formattedField = str_replace('_', ' ', $field);
      $formattedField = ucwords($formattedField);
      $formattedField = str_replace(' ', '', $formattedField);
      match($fieldType) {
        'string', 'text', 'text_long', 'string_long' => $this->stringTest($class, $formattedField),
        //'integer' => $this->integerGetSet($class, $field),
        //'boolean' => $this->booleanGetSet($class, $field),
        //default => $this->stringGetSet($class, $field),
        default => NULL,
      };
    }
  }

  private function stringTest(ClassType $class, string $formattedField): void {
    $testMethod = '$entity = $this->createEntity();' . PHP_EOL;
    $testMethod .= \sprintf('$this->assertNull($entity->get%s());' . PHP_EOL, $formattedField);
    $testMethod .= \sprintf('$entity->set%s(\'test\');' . PHP_EOL, $formattedField);
    $testMethod .= \sprintf('$this->assertEquals(\'test\', $entity->get%s());', $formattedField);
    $class->addMethod('test' . $formattedField)
      ->setPublic()
      ->setBody($testMethod);
  }

}
