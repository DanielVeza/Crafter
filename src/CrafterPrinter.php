<?php

namespace Drupal\crafter;

use Nette\PhpGenerator\ClassType;
use Nette\PhpGenerator\EnumType;
use Nette\PhpGenerator\InterfaceType;
use Nette\PhpGenerator\PhpNamespace;
use Nette\PhpGenerator\TraitType;

class CrafterPrinter extends \Nette\PhpGenerator\Printer {

  public string $indentation = '  ';
  public bool $bracesOnNextLine = FALSE;

  public function printClass(TraitType|InterfaceType|ClassType|EnumType $class, ?PhpNamespace $namespace = null,): string {
    $output = '<?php' . PHP_EOL . PHP_EOL . 'declare(strict_types=1);' . PHP_EOL . PHP_EOL;
    $output .= $this->printNamespace($namespace);
    $output .= parent::printClass($class, $namespace);
    $output = str_replace(PHP_EOL . '{', ' {', $output);
    return $output;
  }
}
