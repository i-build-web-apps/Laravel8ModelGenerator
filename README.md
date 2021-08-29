# Laravel Model Generator
###Generates Laravel 8 models from existing MySQL database schema.

Use composer to create the stub with
```
php artisan make:command GenerateModelFromMySQL
```

Copy GenerateModelFromMySQL.php to app/Console/Commands/

Update app/Console/Kernel.php and add to the $commands[] array:
```
\App\Console\Commands\GenerateModelFromMySQL::class,
```

Set up your MySQL connection within Laravel, then use the generator as follows:
```
php artisan generate:model <database>.<table>
```

Table param accepts the * wildcard.

After the models are generated, uncomment the required $fillable fields.
Timestamps field is included, but probably not required when the model is generated in the MySQL->Laravel direction.
