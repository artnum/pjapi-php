<?php

namespace PJAPI;

use stdClass;
use PDO;

class Search {
    protected stdClass $search;
    protected string $fieldPrefix = '';

    public function __construct() 
    {
    }

    public function setSearch (stdClass $search) {
        $this->search = $search;
    }

    public function getSearch () {
        return $this->search;
    }

    public function setFieldPrefix (string $prefix) {
        $this->fieldPrefix = $prefix;
    }

    public function getFieldPrefix () {
        return $this->fieldPrefix;
    }

    private function sqlUnaryOperator (string $operator, string $field) {
        if (!empty($this->fieldPrefix)) {
            $field = $this->fieldPrefix . $field;
        }
        switch ($operator) {
            default: return null;
            case 'isnull': return 'IS NULL ' . $field;
            case 'isnotnull': return 'IS NOT NULL ' . $field;
            case 'isempty': return 'IS NULL NULLIF(' . $field . ', \'\')';
            case 'isnotempty': return 'IS NOT NULL NULLIF(' . $field . ', \'\')';
        }
    }

    private function sqlConvertValue ($value, string $type = '') {
        if (!empty($type)) {
            switch (strtolower($type)) {
                default:
                case 'string':
                case 'str':
                    return [strval($value), PDO::PARAM_STR];
                case 'interger':
                case 'int': 
                    return [intval($value), PDO::PARAM_INT];
                case 'double':
                case 'real':
                case 'float': 
                    return [strval($value), PDO::PARAM_STR];
                case 'boolean':
                case 'bool':
                    if (is_string($value)) {
                        switch(strtolower($value)) {
                            case 'true':
                            case '1':
                            case 'on':
                            case 'yes':
                            case 'y':
                            case 't':
                                return [true, PDO::PARAM_BOOL];
                            case 'false':
                            case '0':
                            case 'off':
                            case 'no':
                            case 'n':
                            case 'f':
                                return [false, PDO::PARAM_BOOL];
                        }
                    }
                    return [!!$value, PDO::PARAM_BOOL];
                case 'null':
                case 'nil': 
                    return [null, PDO::PARAM_NULL];
                }
        }

        return [strval($value), PDO::PARAM_STR];
    }

    private function sqlOperator (string $operator, &$value) {
        switch(strtolower($operator)) {
            case '=':
            case 'eq': 
                return '=';

            case '!=':
            case '<>':
            case 'ne': 
                return '<>';

            case '>':
            case 'gt': 
                return '>';

            case '>=':
            case 'ge': 
                return '>=';
            
            case '<':
            case 'lt': 
                return '<';

            case '<=':
            case 'le':
                return '<=';
            
            case '~':
            case 'like': 
                $value = str_replace('*', '%', strval($value));
                return 'LIKE';
            
            case '!~':
            case 'notlike': 
                $value = str_replace('*', '%', strval($value));
                return 'NOT LIKE';
            }
    }

    public function toSQL (stdClass|null $object = null, $join = 'AND', $deep = 0) {
        $object = $object ?? $this->search;
        $phCount = 0;
        $placeholders = [];
        $predicats = [];
        foreach ($object as $key => $value) {
            $key = explode(':', $key)[0];
            switch (strtolower($key)) {
                case '#and':
                case '#or':
                    list ($a, $b) = $this->toSQL($value,  strtoupper(substr($key, 1)), ++$deep);
                    $predicats[] = '(' . $a . ')';
                    $placeholders = array_merge($placeholders, $b);
                    continue 2;
            }

            if (!preg_match('/^[[:alnum:]_\-.\*]+$/', $key)) {
                continue;
            }
 
            $unaryOp = $this->sqlUnaryOperator(strtolower($value->operator), $key);
            if ($unaryOp) {
                $predicats[] = $unaryOp;
                continue;
            }
            
            list($v, $type) = $this->sqlConvertValue($value->value, $value->type ?? 'str');
            $placeholder = sprintf(':ph%d%d', $deep, ++$phCount);
            $operator = $this->sqlOperator($value->operator, $v);
            $placeholders[$placeholder] = [
                'type' => $type,
                'value' => $v
            ];
            
            $field = $key;
            if (!empty($this->fieldPrefix)) {
                $field = $this->fieldPrefix . $field;
            }
            $predicats[] = $field . ' ' . $operator. ' ' . $placeholder;
        }
        
        return [implode(' ' . $join . ' ', $predicats), $placeholders];
    }
}