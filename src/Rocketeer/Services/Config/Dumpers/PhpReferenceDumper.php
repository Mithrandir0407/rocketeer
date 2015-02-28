<?php
namespace Rocketeer\Services\Config\Dumpers;

use Rocketeer\Services\Config\TreeBuilder\ClosureNode;
use SuperClosure\Analyzer\AstAnalyzer;
use Symfony\Component\Config\Definition\ArrayNode;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\EnumNode;
use Symfony\Component\Config\Definition\NodeInterface;
use Symfony\Component\Config\Definition\PrototypedArrayNode;

/**
 * Dumps a Symfony reference in PHP format
 *
 * @author Maxime Fabre <ehtnam6@gmail.com>
 */
class PhpReferenceDumper
{
    /**
     * @type string
     */
    protected $reference;

    /**
     * @param ConfigurationInterface $configuration
     * @param                        string null                   $namespace
     *
     * @return string
     */
    public function dump(ConfigurationInterface $configuration, $namespace = null)
    {
        return $this->dumpNode($configuration->getConfigTreeBuilder()->buildTree(), $namespace);
    }

    /**
     * @param NodeInterface $node
     * @param string|null   $namespace
     *
     * @return string
     */
    public function dumpNode(NodeInterface $node, $namespace = null)
    {
        $this->reference = '';
        $this->writeNode($node, 1, true, $namespace);

        $reference       = $this->reference;
        $this->reference = null;

        return '<?php return array('.PHP_EOL.$reference.PHP_EOL.');';
    }

    /**
     * @param NodeInterface $node
     * @param int           $depth
     */
    private function writeNode(NodeInterface $node, $depth = 0)
    {
        $comments     = array();
        $default      = '';
        $defaultArray = null;
        $children     = null;
        $example      = $node->getExample();
        $isCoreNode   = $node->getParent()->getName() === 'rocketeer';

        if ($node instanceof ArrayNode) {
            $children = $node->getChildren();

            if ($node instanceof PrototypedArrayNode) {
                $prototype = $node->getPrototype();

                if ($prototype instanceof ArrayNode) {
                    $children = $prototype->getChildren();
                }

                // check for attribute as key
                if ($key = $node->getKeyAttribute()) {
                    $keyNodeClass = 'Symfony\Component\Config\Definition\\'.($prototype instanceof ArrayNode ? 'ArrayNode' : 'ScalarNode');
                    $keyNode      = new $keyNodeClass($key, $node);
                    $keyNode->setInfo('Prototype');

                    // add children
                    foreach ($children as $childNode) {
                        $keyNode->addChild($childNode);
                    }
                    $children = array($key => $keyNode);
                }

                if (!$children) {
                    if ($node->hasDefaultValue() && count($defaultArray = $node->getDefaultValue())) {
                        $default = '';
                    } elseif (!is_array($example)) {
                        $default = [];
                    }
                }
            }
        } elseif ($node instanceof EnumNode) {
            $comments[] = 'One of '.implode(', ', array_map('json_encode', $node->getValues()));
            $default    = $node->getDefaultValue();
        } elseif ($node instanceof ClosureNode) {
            $default  = $node->getDefaultValue();
            $analyzer = new AstAnalyzer();
            $default  = $analyzer->analyze($default)['code'];
        } else {
            $default = null;

            if ($node->hasDefaultValue()) {
                $default = $node->getDefaultValue();

                if (is_array($default)) {
                    if (count($defaultArray = $node->getDefaultValue())) {
                        $default = '';
                    } elseif (!is_array($example)) {
                        $default = [];
                    }
                }
            }
        }

        // required?
        if ($node->isRequired()) {
            $comments[] = 'Required';
        }

        // example
        if ($example && !is_array($example)) {
            $comments[] = 'Example: '.$example;
        }

        // Format comments and values
        $comments = count($comments) ? '// '.implode(', ', $comments) : '';
        $name     = var_export($node->getName(), true).' => ';
        $format   = '%-20s %s %s';

        if ($node instanceof ArrayNode) {
            $name .= '[';
            $default = (!$example && !$children && !$defaultArray) ? '],' : null;
            $format  = '%s%s %s';
        } elseif ($node instanceof ClosureNode) {
            $default = substr($default, 0, -1).',';
        } else {
            $default = $default === "\n" ? '"\n"' : var_export($default, true);
            $default .= ',';
        }

        // Output informations
        if ($info = $node->getInfo()) {
            $this->writeLine('');

            $info = str_replace("\n", sprintf("\n%".($depth * 4)."s// ", ' '), $info);
            $this->writeLine('// '.$info, $depth * 4);

            if ($isCoreNode) {
                $this->writeLine(str_repeat('/', 70), $depth * 4);
            }
        }

        $name    = $isCoreNode ? null : $name;
        $default = str_replace("\n", sprintf("\n%".($depth * 4)."s ", ' '), $default);
        $text    = rtrim(sprintf($format, $name, $default, $comments), ' ');

        // Output default
        $this->writeLine($text, $depth * 4);

        // Output defaults
        if ($defaultArray) {
            $message = count($defaultArray) > 1 ? 'Defaults' : 'Default';
            $childDepth = $depth * 4 + 4;
            $childDepth-= $isCoreNode ? 4 : 0;

            $this->writeLine('// '.$message.':', $childDepth);
            $this->writeArray($defaultArray, $childDepth);
        }

        // Output examples
        if (is_array($example)) {
            $message = count($example) > 1 ? 'Examples' : 'Example';

            $this->writeLine('// '.$message.':', $depth * 4 + 4);
            $this->writeArray($example, $depth * 4 + 4, true);
        }

        if ($node instanceof ArrayNode) {
            if ($children) {
                foreach ($children as $childNode) {
                    $this->writeNode($childNode, $depth + !$isCoreNode);
                }
            }

            if (!$isCoreNode && ($children || $example || $defaultArray)) {
                $this->writeLine('],', $depth * 4);
            }
        }
    }

    /**
     * Outputs a single config reference line
     *
     * @param string  $text
     * @param integer $indent
     */
    private function writeLine($text, $indent = 0)
    {
        $indent = strlen($text) + $indent;
        $format = '%'.$indent.'s';

        $this->reference .= sprintf($format, $text)."\n";
    }

    /**
     * Output an array
     *
     * @param array   $array
     * @param integer $depth
     * @param boolean $comments
     */
    private function writeArray(array $array, $depth, $comments = false)
    {
        // Else dump each value on its own line
        $isIndexed = array_values($array) === $array;
        $comments  = $comments ? '// ' : null;

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $val = '';
            } else {
                $val = $value;
            }

            $key = var_export($key, true);
            $val = var_export($val, true);
            if ($isIndexed) {
                $this->writeLine($comments.$val.',', $depth);
            } else {
                $this->writeLine($comments.sprintf('%s => %s,', $key, $val), $depth);
            }

            if (is_array($value)) {
                $this->writeArray($value, $depth + 1);
            }
        }
    }
}
