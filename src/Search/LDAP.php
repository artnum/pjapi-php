<?php

namespace PJAPI\Search;

use stdClass;

trait LDAP {

    private function ldapUnaryOperator(string $operator, string $field):string|null {
        if (!empty($this->fieldPrefix)) {
            $field = $this->fieldPrefix . $field;
        }
        $field = ldap_escape($field, '', LDAP_ESCAPE_FILTER);
        switch ($operator) {
            default: return null;
            case 'isnull': return '(!(' . $field . '=*))';
            case 'isnotnull': return '(' . $field . '=*)';
            case 'isempty': return '(!(' . $field . '=*))';
            case 'isnotempty': return '(' . $field . '=*)';
        }
    }

    private function ldapPredicat (string $operator, string $field, string $value) {
        if (!empty($this->fieldPrefix)) {
            $field = $this->fieldPrefix . $field;
        }
        $field = ldap_escape($field, '', LDAP_ESCAPE_FILTER);
        switch(strtolower($operator)) {
            case '=':
            case 'eq': 
                return '(' . $field . '=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . ')';

            case '!=':
            case '<>':
            case 'ne': 
                return '(!(' . $field . '=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . '))';

            case '>':
            case 'gt': 
                return '(' . $field . '>=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . ')';

            case '>=':
            case 'ge': 
                return '(' . $field . '>=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . ')';
            
            case '<':
            case 'lt': 
                return '(' . $field . '<' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . ')';

            case '<=':
            case 'le':
                return '(' . $field . '<=' . ldap_escape($value, '', LDAP_ESCAPE_FILTER) . ')';
            
            case '~':
            case 'like': 
                return '(' . $field . '=' . ldap_escape($value, '*', LDAP_ESCAPE_FILTER) . ')';
            case '!~':
            case 'notlike': 
                return '(!(' . $field . '=' . ldap_escape($value, '*', LDAP_ESCAPE_FILTER) . '))';
        }
    }

    public function toLDAP(stdClass|null $object = null, string $join = '&', int $deep = 0) {
        $object = $object ?? $this->search;
        $predicats = [];

        foreach ($object as $key => $value) {
            if (!is_object($value)) {
                $value = (object) ['value' => $value, 'operator' => '=', 'type' => 'str'];
            }
            $key = explode(':', $key)[0];
            switch (strtolower($key)) {
                case '#and':
                case '#or':
                    
                    $predicats[] = $this->toLDAP($value, strtolower($key) === '#or' ? '|' : '&', $deep + 1);
                    continue 2;
            }

            $unary = $this->ldapUnaryOperator($value->operator, $key);
            if ($unary !== null) {
                $predicats[] = $unary;
                continue;
            }
            $predicats[] = $this->ldapPredicat($value->operator, $key, strval($value->value));
        }

        return '(' . $join . '' . implode('', $predicats) . ')';
    }
}