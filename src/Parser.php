<?php

declare(strict_types=1);

namespace Fpp;

if (! defined('T_OTHER')) {
    define('T_OTHER', 100000);
}

final class Parser
{
    /**
     * @var int
     */
    private $tokenCount;

    /**
     * @var int
     */
    private $line = 0;

    /**
     * @var bool
     */
    private $namespaceFound = false;

    public function parse(string $contents): DefinitionCollection
    {
        $collection = new DefinitionCollection();

        $tokens = token_get_all("<?php\n\n$contents");
        $this->tokenCount = count($tokens);
        $position = 0;
        $namespace = '';

        $token = $this->nextToken($tokens, $position);

        while ($position < $this->tokenCount - 1) {
            switch ($token[0]) {
                case T_OPEN_TAG:
                    break;
                case T_NAMESPACE:
                    if ($this->namespaceFound) {
                        throw ParseError::nestedNamespacesDetected($token[2]);
                    }
                    $namespace = $this->parseNamespace($tokens, $position);
                    break;
                case T_STRING:
                    switch (ucfirst($token[1])) {
                        case Type\Data::VALUE:
                            list($name) = $this->parseName($tokens, $position);
                            list($arguments) = $this->parseArguments($tokens, $position);
                            list($derivings, $token) = $this->parseDerivings($tokens, $position, true);
                            $collection->addDefinition(new Definition(new Type\Data(), $namespace, $name, $arguments, $derivings));

                            if ($token[0] === T_STRING) {
                                // next definition found
                                continue 3;
                            }
                            break;
                        case Type\Enum::VALUE:
                            list($name) = $this->parseName($tokens, $position);
                            list($arguments, $token) = $this->parseEnumTypes($tokens, $position);
                            $collection->addDefinition(new Definition(new Type\Enum(), $namespace, $name, $arguments, [], null));

                            if ($token[0] === T_STRING) {
                                // next definition found
                                continue 3;
                            }
                            break;
                        case Type\AggregateChanged::VALUE:
                        case Type\Command::VALUE:
                        case Type\DomainEvent::VALUE:
                        case Type\Query::VALUE:
                            list($name, $messageName) = $this->parseNameWithMessage($tokens, $position);
                            list($arguments) = $this->parseArguments($tokens, $position);
                            list($derivings, $token) = $this->parseDerivings($tokens, $position, false);
                            $collection->addDefinition(new Definition(new Type\Query(), $namespace, $name, $arguments, $derivings, $messageName));

                            if ($token[0] === T_STRING) {
                                // next definition found
                                continue 3;
                            }
                            break;
                    }
                    break;
                case T_WHITESPACE:
                    break;
                case T_OTHER:
                    if ($token[1] === '}') {
                        if ($this->namespaceFound) {
                            $this->namespaceFound = false;
                            $namespace = '';
                        } else {
                            throw ParseError::unexpectedTokenFound('T_STRING or T_WHITESPACE', $token);
                        }
                    }
                    break;
                default:
                    throw ParseError::unexpectedTokenFound('T_STRING or T_WHITESPACE', $token);
            }

            if ($position + 1 < $this->tokenCount) {
                $token = $this->nextToken($tokens, $position);
            }
        }

        return $collection;
    }

    private function nextToken(array $tokens, int &$position): array
    {
        if ($position === $this->tokenCount - 1) {
            throw ParseError::unexpectedEndOfFile();
        }

        $token = $tokens[++$position];

        if (! is_array($token)) {
            $token = [
                T_OTHER,
                $token,
                $this->line,
            ];
        } else {
            $token[2] = $token[2] - 2;
            $this->line = $token[2];
        }

        return $token;
    }

    private function parseNamespace(array $tokens, int &$position): string
    {
        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_WHITESPACE) {
            throw ParseError::unexpectedTokenFound(' ', $token);
        }

        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_STRING) {
            throw ParseError::expectedString($token);
        }

        $namespace = $token[1];

        $token = $this->nextToken($tokens, $position);

        while ($token[0] === T_NS_SEPARATOR) {
            $token = $this->nextToken($tokens, $position);

            if ($token[0] !== T_STRING) {
                throw ParseError::expectedString($token);
            }

            $namespace .= '\\' . $token[1];

            $token = $this->nextToken($tokens, $position);
        }

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        if ($token[1] === '{') {
            $this->namespaceFound = true;

            return $namespace;
        }

        if ($token[1] !== ';') {
            throw ParseError::unexpectedTokenFound(';', $token);
        }

        return $namespace;
    }

    private function parseName(array $tokens, int &$position): array
    {
        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_WHITESPACE) {
            throw ParseError::unexpectedTokenFound(' ', $token);
        }

        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_STRING) {
            throw ParseError::expectedString($token);
        }

        $name = $token[1];

        $token = $this->nextToken($tokens, $position);

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        if ($token[1] !== '=') {
            throw ParseError::unexpectedTokenFound('=', $token);
        }

        return [$name, $token];
    }

    private function parseNameWithMessage(array $tokens, int &$position): array
    {
        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_WHITESPACE) {
            throw ParseError::unexpectedTokenFound(' ', $token);
        }

        $token = $this->nextToken($tokens, $position);

        if ($token[0] !== T_STRING) {
            throw ParseError::expectedString($token);
        }

        $name = $token[1];

        $token = $this->nextToken($tokens, $position);

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        $messageName = null;

        if ($token[1] === ':') {
            $token = $this->nextToken($tokens, $position);

            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] !== T_STRING) {
                throw ParseError::unexpectedTokenFound('T_STRING', $token);
            }

            $messageName = $token[1];

            $token = $this->nextToken($tokens, $position);

            while ($token[0] !== T_WHITESPACE
                && $token[1] !== '='
            ) {
                $messageName .= $token[1];

                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }
        }

        if ($token[1] !== '=') {
            throw ParseError::unexpectedTokenFound('=', $token);
        }

        return [$name, $messageName, $token];
    }

    private function parseArguments(array $tokens, int &$position): array
    {
        $arguments = [];

        $token = $this->nextToken($tokens, $position);

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        if ($token[1] !== '{') {
            throw ParseError::unexpectedTokenFound('{', $token);
        }

        $token = $this->nextToken($tokens, $position);

        while ($token[1] !== '}') {
            $typehint = null;

            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[1] === '?') {
                $typehint = '?';
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] !== T_STRING) {
                throw ParseError::expectedString($token);
            }

            $typehint .= $token[1];
            $token = $this->nextToken($tokens, $position);

            if ($token[0] !== T_WHITESPACE) {
                throw ParseError::unexpectedTokenFound(' ', $token);
            }

            $token = $this->nextToken($tokens, $position);

            if ($token[0] !== T_VARIABLE) {
                throw ParseError::unexpectedTokenFound('T_VARIABLE', $token);
            }

            $name = substr($token[1], 1);
            $arguments[] = new Argument($name, $typehint);

            $token = $this->nextToken($tokens, $position);

            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[1] === ',') {
                $token = $this->nextToken($tokens, $position);
            }
        }

        return [$arguments, $token];
    }

    private function parseEnumTypes(array $tokens, int &$position): array
    {
        $arguments = [];

        $token = $this->nextToken($tokens, $position);

        while (true) {
            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] !== T_STRING) {
                throw ParseError::expectedString($token);
            }

            $name = $token[1];

            $arguments[] = new Argument($name, null);

            if ($position === $this->tokenCount - 1) {
                break;
            }

            $token = $this->nextToken($tokens, $position);

            if ($token[0] === T_WHITESPACE) {
                if ($position === $this->tokenCount - 1) {
                    break;
                }
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[1] === '|') {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] === T_WHITESPACE) {
                if ($position === $this->tokenCount - 1) {
                    break;
                }
                $token = $this->nextToken($tokens, $position);
            }

            if (in_array(ucfirst($token[1]), Type::OPTION_VALUES)) {
                break;
            }
        }

        return [$arguments, $token];
    }

    private function parseDerivings(array $tokens, int &$position, bool $allow): array
    {
        $derivings = [];

        if (($this->tokenCount - 1) === $position) {
            return [$derivings, null];
        }

        $token = $this->nextToken($tokens, $position);

        if (($this->tokenCount - 1) === $position) {
            return [$derivings, null];
        }

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        if (! $allow && $token[1] === 'deriving') {
            throw ParseError::unknownDeriving($token[2]);
        }

        if ($token[1] !== 'deriving') {
            return [$derivings, $token];
        }

        $token = $this->nextToken($tokens, $position);

        if ($token[0] === T_WHITESPACE) {
            $token = $this->nextToken($tokens, $position);
        }

        if ($token[1] !== '(') {
            throw ParseError::unexpectedTokenFound('(', $token);
        }

        $token = $this->nextToken($tokens, $position);

        while ($token[1] !== ')') {
            if ($token[0] === T_WHITESPACE) {
                $token = $this->nextToken($tokens, $position);
            }

            if ($token[0] !== T_STRING) {
                throw ParseError::expectedString($token);
            }

            if (! in_array($token[1], Deriving::OPTION_VALUES, true)) {
                throw ParseError::unknownDeriving($token[2]);
            }

            $fqcn = __NAMESPACE__ . '\\Deriving\\' . $token[1];
            $derivings[] = new $fqcn();

            $token = $this->nextToken($tokens, $position);

            if ($token[0] === T_WHITESPACE) {
                throw ParseError::unexpectedTokenFound(' ', $token);
            }

            if ($token[1] === ',') {
                $token = $this->nextToken($tokens, $position);
            }
        }

        return [$derivings, $token];
    }
}
