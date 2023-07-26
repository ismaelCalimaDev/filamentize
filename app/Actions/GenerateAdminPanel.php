<?php

namespace App\Actions;

use App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use ReflectionClass;
use ReflectionNamedType;

class GenerateAdminPanel
{
    use AsAction;

    public string $commandSignature = 'generate:filamentize';

    public $tables;
    public $directory;

    public function __construct()
    {
        $this->tables = Schema::getAllTables();
        $this->directory = app_path("Models");
    }

    public function handle()
    {
        $tableReference = 'Tables_in_'.config('database.connections.mysql.database');

        $this->generateAllModels($tableReference);

        $this->addBelongsToToModels($tableReference);

        $this->generateHasManyRelationships();

        $this->generateFilamentResources($tableReference);
    }

    private function generateAllModels($tableReference)
    {
        foreach ($this->tables as $table) {
            $tableName = $this->getTableName($table, $tableReference);
            $fileName = $this->getFileName($tableName);

            if (!File::exists($this->directory)) {
                File::makeDirectory($this->directory);
            }

            if($fileName === 'User') {
                continue;
            }
            File::put($this->directory.'\\'. $fileName.'.php', "<?php\n\nnamespace App\Models;\n\nuse Illuminate\Database\Eloquent\Factories\HasFactory;\nuse Illuminate\Database\Eloquent\Model;\nuse Illuminate\Database\Eloquent\Relations\BelongsTo;\nuse Illuminate\Database\Eloquent\Relations\BelongsToMany;\nuse Illuminate\Database\Eloquent\Relations\HasMany;\n\nclass $fileName extends Model\n{\n\tprotected \$table = '$tableName';\n\tprotected \$guarded = [];\n}");
        }
    }

    private function generateHasManyRelationships()
    {
        $files = File::allFiles(app_path("Models"));

        foreach ($files as $file) {
            $class = 'App\Models\\'.$file->getFilenameWithoutExtension();
            $class = new $class();
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods();

            foreach ($methods as $method) {
                $returnType = $method->getReturnType();

                if ($returnType instanceof ReflectionNamedType && $returnType->getName() === BelongsTo::class) {
                    $relatedModel = str($method->getName())->ucfirst()->append('.php');
                    $prevContent = File::get(app_path("Models\\$relatedModel"));
                    if($relatedModel !== 'user') {
                        File::put(app_path("Models\\$relatedModel"), str($prevContent)->replaceLast('}', '')."\n\tpublic function ".str($file->getFilenameWithoutExtension())->plural()->lcfirst()."(): HasMany\n\t{\n\t\treturn \$this->hasMany(".str($file->getFilenameWithoutExtension())->ucfirst()."::class);\n\t}\n}");
                    }
                }
            }
        }
    }


    private function addBelongsToToModels($tableReference): void
    {
        foreach ($this->tables as $table) {
            $tableName = $this->getTableName($table, $tableReference);
            $fileName = $this->getFileName($tableName);

            $foreignKeys = Schema::getConnection()->getDoctrineSchemaManager()->listTableForeignKeys($tableName);
            foreach ($foreignKeys as $foreignKey) {
                $localColumns = $foreignKey->getLocalColumns();
                $referencedTable = $foreignKey->getForeignTableName();
                if($referencedTable) {
                    $relationshipFileName = Str::singular(Str::ucfirst(Str::camel($referencedTable)));
                    $prevContent = File::get($this->directory.'\\'. $fileName.'.php');
                    $relationship = "\n\tpublic function ".Str::singular(Str::camel($referencedTable))."(): BelongsTo\n\t{\n\t\treturn \$this->belongsTo(".$relationshipFileName."::class, '$localColumns[0]');\n\t}\n}";
                    File::put($this->directory.'\\'. $fileName.'.php', str($prevContent)->replaceLast('}', '').$relationship);
                }
            }
        }
    }

    private function generateFilamentResources($tableReference): void
    {
        foreach ($this->tables as $table) {
            $tableName = $this->getTableName($table, $tableReference);
            $fileName = $this->getFileName($tableName);
            Artisan::call("make:filament-resource $fileName --generate");
            $this->formatFilamentData($fileName);
        }
    }

    private function getFileName(string $tableName): string
    {
        return str($tableName)->camel()->ucfirst()->singular()->value();
    }

    private function getTableName($table, $tableReference): string
    {
        return $table->$tableReference;
    }

    private function formatFilamentData(string $fileName)
    {
            $path = app_path('Filament\Resources\\').$fileName."Resource.php";
            $fileLines = $this->getModifiedLines($path, $fileName);
            $newFileContent = implode("\n", $fileLines);
            File::put($path, $newFileContent);
    }

    private function getRelationMethods(string $model): array
    {
        try {
            $modelClass = new $model();
            $reflection = new ReflectionClass($modelClass);
            $methods= $reflection->getMethods();

            $relationMethods = [];
            foreach ($methods as $method) {
                if($method->getReturnType()?->getName() === BelongsTo::class || $method->getReturnType()?->getName() === HasMany::class) {
                    $relationMethods[] = $method->getName();
                }
            }
            return $relationMethods;
        }catch (\Error $error) {
            logger($error);
        }
    }

    private function getNewFilamentField(string $relationFileName)
    {
        try {
            $model = "App\Models\\$relationFileName";
            $modelClass= new $model();

            $table = $modelClass->getTable();
            $columns = Schema::getColumnListing($table);

            $foreignKeys =  DB::getDoctrineSchemaManager()->listTableForeignKeys($table);
            $noForeignKeys = array_filter($columns, function ($column) use ($foreignKeys) {
                return !in_array($column, array_keys($foreignKeys)) && stripos(str($column)->lower()->value(), 'id') === false;
            });

            foreach ($noForeignKeys as $foreignKey) {
                if(str($foreignKey)->contains(['name', 'title', 'text'])) {
                    $field = $foreignKey;
                    break;
                }
                $field = $foreignKey;
            }
            return $field;
        } catch (\Error $error) {
            logger($error);
        }
    }

    private function getModifiedLines($path, $fileName): array
    {
        $model = "App\Models\\$fileName";
        $relationMethods = $this->getRelationMethods($model);

        $file = File::get($path);
        $fileLines = explode("\n", $file);


        foreach ($fileLines as $key => $line) {
            if(str($line)->contains($relationMethods)){
                if(str($line)->contains('->relationship')) {
                    $filamentField = str($line)->between("->relationship(", ")")->value();
                    $relation = str($filamentField)->betweenFirst("'", "'")->value();
                    $relationField = str($filamentField)->after($relation."'")->between("'", "'")->value();
                    $newField = $this->getNewFilamentField(str($relation)->ucfirst()->value());
                    $newLine = str($line)->replace($relationField, $newField)->value();
                    $fileLines[$key] = $newLine;
                    continue;
                }
                $filamentField = str($line)->betweenFirst('(', ')');
                $relationFileName = str($filamentField)->between("'", ".");
                if($this->getNewFilamentField(str($relationFileName)->ucfirst())) {
                    $newField = "'".$relationFileName.'.'.$this->getNewFilamentField(str($relationFileName)->ucfirst())."'";
                    $newLine = str($line)->replace($filamentField, $newField);
                    $fileLines[$key] = $newLine;
                }
            }
        }
        return $fileLines;
    }
}
