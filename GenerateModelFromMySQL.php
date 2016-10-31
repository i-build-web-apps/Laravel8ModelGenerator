<?php namespace App\Console\Commands;

use DB;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class GenerateModelFromMySQL extends Command
{

	/**
	 * The console command name.
	 *
	 * @var string
	 */
	protected $name = 'generate:model';

	/**
	 * The console command description.
	 *
	 * @var string
	 */
	protected $description = 'Generate model from MySQL database.';

	/**
	 * Create a new command instance.
	 *
	 * @return \App\Console\Commands\GenerateModelFromMySQL
	 */
	public function __construct()
	{
		parent::__construct();

	}

	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function fire()
	{
		preg_match('/(.+)\.(.+)/', $this->argument('database_table'), $matches);
		if (empty($matches[1]) || empty($matches[2]))
		{
			$this->error('Please enter a valid Database/Table.');
			exit();
		}
		$database_name = $matches[1];
		$table_name    = $matches[2];

		//Match the tables
		$tables = $this->getMatchingTables($database_name, $table_name);

		if(count($tables) == 0)
		{
			$this->error('Error: No tables found that match your argument: ' . $table_name);
			exit();
		}

		$files_exist = false;
		foreach ($tables AS $table)
		{
			$files_exist = true;
			if (file_exists('app/' . $this->camelCase1($table->name) . '.php'))
				$this->error("Table: {$database_name}.{$table->name}");
			else
				$this->info("Table: {$database_name}.{$table->name}");
		}

		$this->comment($this->rules());

		if (!$this->confirm('Are you happy to proceed? [yes|no]'))
		{
			$this->error('Error: User is a chicken.');
			exit();
		}

		foreach ($tables AS $table)
		{
			$template = $this->template();

			$fields          = $this->getTableFields($database_name, $table->name);
			$solo_relations  = $this->getTableFieldsSoloRelations($database_name, $table->name);
			$multi_relations = $this->getTableFieldsMultiRelations($database_name, $table->name);

			$template = preg_replace('/#CLASS_NAME#/', $this->camelCase1($table->name), $template);
			$template = preg_replace('/#TABLE_NAME#/', $table->name, $template);
			$template = preg_replace('/#FILLABLE#/', $this->generateFillable($fields), $template);
			$template = preg_replace('/#SOLO_RELATIONAL_FUNCTIONS#/', $this->GenerateSoloRelations($solo_relations), $template);
			$template = preg_replace('/#MULTI_RELATIONAL_FUNCTIONS#/', $this->GenerateMultiRelations($multi_relations), $template);
			$template = preg_replace('/#IMPORT_SOFT_DELETE#/', $this->useSofDelete($fields, 'import'), $template);
			$template = preg_replace('/#USE_SOFT_DELETE#/', $this->useSofDelete($fields, 'use'), $template);

			file_put_contents('app/' . $this->camelCase1($table->name) . '.php', $template);

		}

		$this->info("\n** The models have been created **\n");

	}

	/**
	 * Get the console command arguments.
	 *
	 * @return array
	 */
	protected function getArguments()
	{
		return [
			['database_table', InputArgument::REQUIRED, 'Qualified table name (database.table)'],
		];
	}

	/**
	 * Get the console command options.
	 *
	 * @return array
	 */
	protected function getOptions()
	{
		return [
			//['example', null, InputOption::VALUE_OPTIONAL, 'An example option.', null],
		];
	}

	protected function useSofDelete($table_fields, $option)
	{
		$softDelete= false;
		foreach ($table_fields AS $field)
		{
			if($field->COLUMN_NAME == 'deleted_at')
				$softDelete= true;
		}

		$fillable= '';
		switch ($option){
			case 'import':
				$fillable= 'use Illuminate\Database\Eloquent\SoftDeletes;';
				break;
			case 'use':
				$fillable= 'use SoftDeletes;';
				break;
		}
		return ($softDelete)? $fillable : '';
	}

	private function getMatchingTables($database, $table)
	{
		$string = preg_replace('/\*/', '%', $table);

		return DB::select("SELECT TABLE_NAME AS name FROM information_schema.tables
							WHERE TABLE_SCHEMA='{$database}' AND TABLE_NAME LIKE '{$string}'");

	}

	private function generateSoloRelations($fields)
	{
		$solo_relations = "\n/**  One-to-Many Relations  **/\n\n";

		foreach ($fields AS $field)
		{
			$camel_field = $this->camelCase1($field->REFERENCED_TABLE_NAME);
			$solo_relations .= "\tpublic function {$camel_field}()
\t{
\t\treturn \$this->hasOne('App\\{$camel_field}', '{$field->REFERENCED_COLUMN_NAME}', '{$field->COLUMN_NAME}');
\t}\n\n";
		}
		return $solo_relations;
	}

	private function generateMultiRelations($fields)
	{
		$multi_relations = "\n/**  Many-to-One Relations  **/\n\n";

		foreach ($fields AS $field)
		{
			$camel_field = $this->camelCase1($field->TABLE_NAME);
			$multi_relations .= "\tpublic function {$camel_field}s()
\t{
\t\treturn \$this->hasMany('App\\{$camel_field}', '{$field->COLUMN_NAME}', '{$field->REFERENCED_COLUMN_NAME}');
\t}\n\n";
		}
		return $multi_relations;
	}

	//Camel case with init cap
	private function camelCase1($string, array $noStrip = [])
	{
		// non-alpha and non-numeric characters become spaces
		$string = preg_replace('/[^a-z0-9' . implode("", $noStrip) . ']+/i', ' ', $string);
		$string = trim($string);
		// uppercase the first character of each word
		$string = ucwords($string);
		$string = str_replace(" ", "", $string);

		return $string;
	}

	//Camel case with no init cap
	private function camelCase2($string, array $noStrip = [])
	{
		// non-alpha and non-numeric characters become spaces
		$string = preg_replace('/[^a-z0-9' . implode("", $noStrip) . ']+/i', ' ', $string);
		$string = trim($string);
		// uppercase the first character of each word
		$string = ucwords($string);
		$string = str_replace(" ", "", $string);
		$string = lcfirst($string);

		return $string;
	}

	private function getTableFields($database, $table)
	{
		return DB::select("SELECT COLUMN_NAME, COLUMN_DEFAULT, IS_NULLABLE, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH,
							COLUMN_TYPE, COLUMN_KEY, EXTRA, COLUMN_COMMENT FROM information_schema.columns
							WHERE TABLE_SCHEMA='{$database}' AND TABLE_NAME='{$table}'");
	}

	private function getTableFieldsSoloRelations($database, $table)
	{
		return DB::select("SELECT COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME,
							REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
							WHERE TABLE_SCHEMA = '{$database}' AND TABLE_NAME = '{$table}'
							AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_NAME IS NOT NULL
							AND REFERENCED_COLUMN_NAME IS NOT NULL ORDER BY COLUMN_NAME");
	}

	private function getTableFieldsMultiRelations($database, $table)
	{
		return DB::select("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_SCHEMA, REFERENCED_TABLE_NAME,
							REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
							WHERE REFERENCED_TABLE_SCHEMA = '{$database}' AND REFERENCED_TABLE_NAME = '{$table}'
							AND REFERENCED_TABLE_SCHEMA IS NOT NULL AND REFERENCED_TABLE_NAME IS NOT NULL
							AND REFERENCED_COLUMN_NAME IS NOT NULL GROUP BY TABLE_NAME ORDER BY COLUMN_NAME");
	}

	private function rules()
	{
		$rules = "\n==== Please ensure you follow the rules to avoid any problems ====\n";
		$rules .= "1) Table names should be singular.  If you think differently, you are wrong.\n";
		$rules .= "2) Tables have an auto-increment field named 'id'.\n";
		$rules .= "3) The models in RED will be overwritten! (as app/<model>.php)\n";

		return $rules;
	}

	private function generateFillable($table_fields)
	{
		$fillable = "[\n";

		//Field comments, if available
		foreach ($table_fields AS $field)
		{
			if($field->COLUMN_NAME == 'id' || $field->COLUMN_NAME == 'deleted_at')
				$fillable .= "\t\t\t\t//'{$field->COLUMN_NAME}',";
			else
				$fillable .= "\t\t\t\t'{$field->COLUMN_NAME}',";
			if (!empty($field->COLUMN_COMMENT))
				$fillable .= "\t/*{$field->COLUMN_COMMENT}*/";
			$fillable .= "\n";
		}

		$fillable .= "\t\t\t\t]";

		return $fillable;
	}

	private function template()
	{
		return '<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
#IMPORT_SOFT_DELETE#

class #CLASS_NAME# extends Model {

	#USE_SOFT_DELETE#

	protected $table = \'#TABLE_NAME#\';
	public $timestamps = false ;

	public $fillable = #FILLABLE# ;

	#SOLO_RELATIONAL_FUNCTIONS#

	#MULTI_RELATIONAL_FUNCTIONS#

}';
	}

}
