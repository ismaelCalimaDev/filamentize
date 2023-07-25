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

    public function handle()
    {
        $tables = Schema::getAllTables();
        $database = config('database.connections.mysql.database');
        $tableReference = 'Tables_in_'.config('database.connections.mysql.database');
        $directory = app_path("Models");

        foreach ($tables as $table) {
            $tableName = $table->$tableReference;
            $fileName = Str::singular(Str::ucfirst(Str::camel($tableName)));
            $relationship = $this->generateBelongsToRelationships($tableName);

            if (!File::exists($directory)) {
                File::makeDirectory($directory);
            }

            if($fileName !== 'User') {
                File::put($directory.'\\'. $fileName.'.php', "<?php\n\nnamespace App\Models;\n\nuse Illuminate\Database\Eloquent\Factories\HasFactory;\nuse Illuminate\Database\Eloquent\Model;\nuse Illuminate\Database\Eloquent\Relations\BelongsTo;\nuse Illuminate\Database\Eloquent\Relations\BelongsToMany;\nuse Illuminate\Database\Eloquent\Relations\HasMany;\n\nclass $fileName extends Model\n{\n\tprotected \$guarded = [];\n$relationship\n}");
            }
            //Artisan::call("make:filament-resource $fileName --generate");
        }
        $this->generateHasManyRelationships();
    }

    private function generateBelongsToRelationships($tableName)
    {

        $foreignKeys = Schema::getConnection()->getDoctrineSchemaManager()->listTableForeignKeys($tableName);
        $relationship = '';
        foreach ($foreignKeys as $foreignKey) {
            $localColumns = $foreignKey->getLocalColumns();
            $referencedTable = $foreignKey->getForeignTableName();
            if($referencedTable) {
                $relationshipFileName = Str::singular(Str::ucfirst(Str::camel($referencedTable)));
                $relationship = "\n\tpublic function ".Str::singular(Str::camel($referencedTable))."(): BelongsTo\n\t{\n\t\treturn \$this->belongsTo(".$relationshipFileName."::class);\n\t}";
            }
        }
        return $relationship;
    }
    private function generateHasManyRelationships()
    {
        $files = File::allFiles(app_path("Models"));

        foreach ($files as $file) {
            $class = 'App\Models\\'.$file->getFilenameWithoutExtension();
            $class = new $class();
            $reflection = new \ReflectionClass($class);
            $methods = $reflection->getMethods();
            $belongsToRelations = [];

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
}
