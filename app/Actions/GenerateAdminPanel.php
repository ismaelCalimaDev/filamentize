<?php

namespace App\Actions;

use App\Models;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
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
            //todo: check this edge case
            if($fileName !== 'User') {
                File::put($this->directory.'\\'. $fileName.'.php', "<?php\n\nnamespace App\Models;\n\nuse Illuminate\Database\Eloquent\Factories\HasFactory;\nuse Illuminate\Database\Eloquent\Model;\nuse Illuminate\Database\Eloquent\Relations\BelongsTo;\nuse Illuminate\Database\Eloquent\Relations\BelongsToMany;\nuse Illuminate\Database\Eloquent\Relations\HasMany;\n\nclass $fileName extends Model\n{\n\tprotected \$table = '$tableName';\n\tprotected \$guarded = [];\n}");
            }
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
                    if($fileName !== 'User') {
                        File::put($this->directory.'\\'. $fileName.'.php', str($prevContent)->replaceLast('}', '').$relationship);
                    }
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
}
