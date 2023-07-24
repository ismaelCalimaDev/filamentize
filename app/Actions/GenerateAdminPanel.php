<?php

namespace App\Actions;


use App\Models\AuctionPools;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

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
            if (!File::exists($directory)) {
                File::makeDirectory($directory);
            }
            $fileName = Str::ucfirst(Str::camel($tableName));
            File::put($directory.'\\'. $fileName.'.php', "<?php\nnamespace App\Models;\nuse Illuminate\Database\Eloquent\Factories\HasFactory;\nuse Illuminate\Database\Eloquent\Model;\nuse Illuminate\Database\Eloquent\Relations\BelongsToMany;\nclass $fileName extends Model\n{\n\tprotected \$guarded = [];\n}");
            Artisan::call("make:filament-resource $fileName --generate");
        }
    }
}
