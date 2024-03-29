<?php

namespace PJAPI;

use stdClass;
use PDO;

class Search {
    use Search\SQL;
    use Search\LDAP;
    
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
}