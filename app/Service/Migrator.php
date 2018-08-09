<?php
namespace App\Service;

use Stringy\Stringy;

class DBMigrator
{
  protected $created;
  public function __construct()
  {
    $this->created = false;
    if (!$this->checkMigrations()) {
      $this->createMigrations();
      $this->created = true;
    }
  }
  public function created()
  {
    return $this->created;
  }
  public function migrate()
  {
    if (!$this->checkMigrations()) {
      return false;
    }
    $migrations = glob(root() . '/databases/migrations/*.*');
    foreach ($migrations as &$migration) {
      $migration = basename($migration);
    }
    if ($this->checkTableMigration()) {
      $executed = \ORM::for_table('migrations')->select('migration')->whereIn('migration', $migrations)->findArray();
      foreach ($executed as &$migration) {
        $migration = $migration['migration'];
      }
      $execute = array_diff($migrations, $executed);
    } else {
      $this->createTableMigrations();
      $execute = $migrations;
    }
    \ORM::getDb()->beginTransaction();
    try {
      \ORM::getDb()->query('SET FOREIGN_KEY_CHECKS=0;');
      foreach ($execute as $migration) {
        $info = pathinfo($migration);
        switch ($info['extension']) {
          case 'json':
            $data = $this->migrateJSON($migration);
            break;
		  case 'yaml':
			$migration = str_replace('yaml', 'yml', $migration);
		  case 'yml':
			$data = $this->migrateYAML($migration);
			break;
        }

        $this->execute($data);

        $q = "INSERT INTO migrations (migration, date) VALUES (?, NOW())";
        $st = \ORM::getDb()->prepare($q);
        $st->execute([$migration]);
      }
      \ORM::getDb()->query('SET FOREIGN_KEY_CHECKS=1;');
      \ORM::getDb()->commit();
    } catch (\Exception $e) {
      \ORM::getDb()->rollBack();
      throw $e;
    }
  }
  public function rollback()
  {
    if (!$this->checkTableMigration()) {
      return false;
    }
    $last_date = \ORM::for_table('migrations')->select('date')->orderByDesc('date')->findOne()->date;
    $executed = \ORM::for_table('migrations')->where('date', $last_date)->orderByDesc('date')->findMany();
    \ORM::getDb()->beginTransaction();
    try {
      \ORM::getDb()->query('SET FOREIGN_KEY_CHECKS=0;');
      foreach ($executed as $migration) {
        $filename = $migration->migration;
        $this->rollbackFile($filename);

        $q = "DELETE FROM migrations WHERE migration=?";
        $st = \ORM::getDb()->prepare($q);
        $st->execute([$migration->migration]);
      }
      \ORM::getDb()->query('SET FOREIGN_KEY_CHECKS=1;');
      \ORM::getDb()->commit();
    } catch (\Exception $e) {
      \ORM::getDb()->rollBack();
      throw $e;
    }
  }
  protected function rollbackFile($filename)
  {
    $info = pathinfo($filename);
    switch ($info['extension']) {
      case 'json':
        $data = $this->rollbackJSON($filename);
        break;
    }
    $this->execute($data);
  }
  protected function rollbackJSON($filename)
  {
    $data = json_decode(file_get_contents(root() . '/databases/migrations/' . $filename));
    $data->action = 'drop';
    unset($data->columns);
    return $data;
  }
  public function checkTableMigration()
  {
    try {
      \ORM::for_table('migrations')->findMany();
      return true;
    } catch (\Exception $e) {
      return false;
    }
  }
  protected function createTableMigrations()
  {
    $q = "CREATE TABLE migrations (
      id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
      migration VARCHAR(255) NOT NULL,
      date DATETIME NOT NULL,
      PRIMARY KEY(id)
    )";
    \ORM::getDb()->query($q);
  }
  public function checkMigrations()
  {
    $migrations = glob(root() . '/databases/migrations/*.*');
    if (count($migrations) == 0) {
      return false;
    }
    return true;
  }
  public function createMigrations()
  {
    $n = count(glob(root() . '/databases/migrations/*.*'));
    $i = $n + 1;
    $files = glob(root() . '/src/*.php');
    foreach ($files as $file) {
      $info = pathinfo($file);
      $class = '\\Process\\' . Stringy::create($info['filename'])->removeRight('.php')->upperCamelize();
      $properties = $this->getProperties($class);
      $migration = $this->createTable($class, $properties);
      $filename = str_pad($i, 5, '0', \STR_PAD_LEFT) . '_migration_create_' . $migration->table . '.json';
      $data = json_encode($migration, \JSON_PRETTY_PRINT);
      $i ++;
      file_put_contents(root() . '/databases/migrations/' . $filename, $data);
      chmod(root() . '/databases/migrations/' . $filename, 'a+rx');
    }
  }
  protected function getProperties($class_name)
  {
    $ref = new \ReflectionClass($class_name);
    $docs = explode(PHP_EOL, $ref->getDocComment());
    $properties = [];
    foreach ($docs as $line) {
      if (strpos($line, '@') !== false) {
        $parsed = $this->parseProperty($line);
        $properties []= $parsed;
      }
    }
    return $properties;
  }
  protected function parseProperty($line)
  {
    $info = explode(' ', trim(substr($line, strpos($line, '@property') + strlen('@property '))));
    $property = (object) ['name' => $info[1], 'type' => $info[0], 'length' => null, 'primary' => false, 'unsigned' => false, 'foreign' => false, 'null' => false];
    $property->name = substr($property->name, 1);
    array_shift($info);
    array_shift($info);
    foreach ($info as $value) {
      if ($value == 'primary') {
        $property->primary = true;
        continue;
      }
      if (strpos($value, 'length') !== false) {
        list($attr, $value) = explode('=', $value);
        $property->length = (int) $value;
        continue;
      }
      if ($value == 'unsigned') {
        $property->unsigned = true;
        continue;
      }
      if ($value == 'text') {
        $property->type = 'text';
        continue;
      }
    }
    switch ($property->type) {
      case 'string':
        $property->type = 'varchar';
        if ($property->length == null) {
          $property->length = 255;
        }
        break;
      case 'bool':
        $property->type = 'int';
        $property->length = 1;
        break;
      case '\\DateTime':
        $property->type = 'datetime';
        break;
      case '\\DateInterval':
        $property->type = 'varchar';
        $property->length = 50;
        break;
    }
    if ($property->type != strtolower($property->type)) {
      $property->foreign = $property->type;
      $property->type = 'int';
      $property->unsigned = true;
    }
    return $property;
  }
  protected function createTable($class, $properties)
  {
    $ref = new \ReflectionClass($class);
    $table = $ref->getStaticPropertyValue('_table', null);
    if ($table == null) {
      $table = '' . Stringy::create($class)->removeLeft('\\Process\\')->underscored()->append('s');
    }
    $output = (object) ['table' => $table, 'columns' => $properties, 'action' => 'create'];
    return $output;
  }
  protected function execute($data)
  {
    switch ($data->action) {
      case 'create':
        $query = $this->parseCreate($data);
        break;
      case 'drop':
        $query = $this->parseDrop($data);
        break;
    }
    \ORM::getDb()->query($query);
  }
  protected function migrateJSON($migration)
  {
    $data = json_decode(file_get_contents(root() . '/databases/migrations/' . $migration));
    return $data;
  }
  protected function migrateYAML($migration)
  {
	return YamlWrapper::load(root() . '/databases/migrations/' . $migration);
  }
  protected function parseCreate($data)
  {
    $prefix = '' . Stringy::create(config('databases.mysql.prefix'))->replace('\\', '')->underscored()->append('_');
    $q = "CREATE TABLE " . $prefix . $data->table . " (";
    $primary = [];
    $foreign = [];
    foreach ($data->columns as $i => $column) {
      if ($i > 0) {
        $q .= ', ';
      }
      $q .= $column->name . ' ' . strtoupper($column->type);
      if ($column->length != null) {
        $q .= '(' . $column-> length . ')';
      }
      if ($column->unsigned or ($column->primary and $column->type == 'int')) {
        $q .= ' UNSIGNED';
      }
	  if ($column->null) {
		  $q .= ' NULL';
	  } else {
		$q .= ' NOT NULL';
	  }
      if ($column->primary) {
        if ($column->type == 'int' and $column->foreign == null) {
          $q .= ' AUTO_INCREMENT';
        }
        $primary []= $column->name;
      }
      if ($column->foreign != null and is_string($column->foreign)) {
        $class = '\\Process\\' . $column->foreign;
        $ref = new \ReflectionClass($class);
        $table = $ref->getStaticPropertyValue('_table', null);
        if ($table == null) {
          $table = '' . Stringy::create($class)->removeLeft('\\Process\\')->underscored()->append('s');
        }
        $foreign []= (object) ['key' => $column->name, 'references' => $table, 'fkey' => 'id'];
      }
    }
    if (count($primary) > 0) {
      $q .= ', PRIMARY KEY (';
      foreach ($primary as $i => $key) {
        if ($i > 0) {
          $q .= ', ';
        }
        $q .= $key;
      }
      $q .= ')';
    }
    if (count($foreign) > 0) {
      foreach ($foreign as $key) {
        $q .= ', FOREIGN KEY (' . $key->key . ') REFERENCES ' . $key->references . '(' . $key->fkey . ')';
      }
    }

    $q .= ")";
    return $q;
  }
  protected function parseDrop($data)
  {
    $prefix = '' . Stringy::create(config('databases.mysql.prefix'))->replace('\\', '')->underscored()->append('_');
    $q = "DROP TABLE " . $prefix . $data->table;
    return $q;
  }
}
