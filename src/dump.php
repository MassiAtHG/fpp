<?php

declare(strict_types=1);

namespace Fpp;

use Fpp\ClassKeyword\AbstractKeyword;
use Fpp\ClassKeyword\FinalKeyword;
use Fpp\ClassKeyword\NoKeyword;

const dump = '\Fpp\dump';

function dump(DefinitionCollection $collection, callable $loadTemplate, callable $replace): string
{
    $code = <<<CODE
<?php

// this file is auto-generated by prolic/fpp
// don't edit this file manually

declare(strict_types=1);


CODE;

    foreach ($collection->definitions() as $definition) {
        $constructors = $definition->constructors();
        if (1 === count($constructors)) {
            $constructor = $constructors[0];
            $code .= $replace($definition, $constructor, $loadTemplate($definition, $constructor), $collection, new FinalKeyword());
        } else {
            $createBaseClass = true;

            foreach ($constructors as $constructor) {
                if ($definition->namespace()) {
                    $name = str_replace($definition->namespace() . '\\', '', $constructor->name());
                } else {
                    $name = $constructor->name();
                }

                if ($definition->name() === $name) {
                    $keyword = new NoKeyword();
                    $createBaseClass = false;
                } else {
                    $keyword = new FinalKeyword();
                }

                $code .= $replace($definition, $constructor, $loadTemplate($definition, $constructor), $collection, $keyword);
            }

            if ($createBaseClass) {
                $code .= $replace($definition, null, $loadTemplate($definition, null), $collection, new AbstractKeyword());
            }
        }
    }

    return substr($code, 0, -1);
}