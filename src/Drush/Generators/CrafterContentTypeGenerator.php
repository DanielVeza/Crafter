<?php

declare(strict_types=1);

namespace Drupal\crafter\Drush\Generators;

use Drupal\field\FieldStorageConfigInterface;
use DrupalCodeGenerator\Asset\AssetCollection as Assets;
use DrupalCodeGenerator\Attribute\Generator;
use DrupalCodeGenerator\Command\BaseGenerator;
use DrupalCodeGenerator\GeneratorType;

#[Generator(
  name: 'crafter:content-type',
  description: 'Generate a content type',
  aliases: ['c:ct'],
  templatePath: __DIR__,
  type: GeneratorType::MODULE_COMPONENT,
)]
final class CrafterContentTypeGenerator extends BaseGenerator {

  /**
   * {@inheritdoc}
   */
  protected function generate(array &$vars, Assets $assets): void {
    $ir = $this->createInterviewer($vars);

    $vars['machine_name'] = $ir->askMachineName();
    $vars['name'] = $ir->askName();
    $vars['class'] = $ir->ask('Content type name');
  }
}
