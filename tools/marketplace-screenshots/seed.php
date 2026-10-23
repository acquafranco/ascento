<?php
// Datos de DEMOSTRACIÓN para las capturas del marketplace: empresa, proveedores, marcas y
// productos ficticios; imágenes ilustrativas (dibujos). Solo para una base de demo local.
use App\Models\{Company, User};
use App\Models\Marketplace\{Supplier, SupplierProduct};
use App\Services\Media\PhotoProcessor;
use Illuminate\Support\Str;

abort_if(app()->isProduction(), 500, 'Solo para una base de demo local, nunca en producción.');
$IMG = getenv('DEMO_IMG'); // carpeta generada por product-images.cjs
$pw = bcrypt('Kiosco-Verde-8241');
$co = Company::factory()->create(['name' => 'Ascensores Demo', 'slug' => 'ascensores-demo', 'city' => 'Ciudad de ejemplo', 'province' => 'Buenos Aires', 'phone' => '11 0000-0000']);
$co->forceFill(['setup_status' => 'done'])->save();
User::factory()->admin()->create(['company_id' => $co->id, 'name' => 'Usuario Demo', 'email' => 'demo@empresa.test', 'password' => $pw, 'onboarding_skipped_at' => now(), 'email_verified_at' => now()]);
auth()->logout();

$mk = function (string $name, string $email, array $cats, string $coverage) use ($pw) {
    $s = new Supplier(['name' => $name, 'description' => 'Proveedor ficticio para la demostración del marketplace.', 'contact_email' => 'ventas@'.Str::slug($name).'.test', 'contact_phone' => '11 0000-0000', 'coverage' => $coverage, 'categories' => $cats, 'brands' => []]);
    $s->forceFill(['slug' => Str::slug($name), 'country' => 'AR', 'tax_id' => '30-'.random_int(10000000, 99999999).'-1', 'status' => 'approved', 'approved_at' => now()])->save();
    $u = User::factory()->create(['company_id' => null, 'name' => 'Ventas '.$name, 'email' => $email, 'password' => $pw]);
    $u->forceFill(['role' => 'supplier', 'supplier_id' => $s->id, 'email_verified_at' => now()])->save();

    return $s;
};
$s1 = $mk('Proveedor Demo Norte', 'demo@norte.test', ['doors', 'electrical', 'ropes'], 'CABA y GBA; envíos a todo el país');
$s2 = $mk('Repuestos Demo Sur', 'demo@sur.test', ['buttons', 'safety', 'cabin'], 'Zona sur y La Plata');
$s3 = $mk('Componentes Demo Centro', 'demo@centro.test', ['machine', 'electrical'], 'Córdoba, Santa Fe y envíos');

$rows = [
    [$s1, ['name' => 'Operador de puerta con variador', 'category' => 'doors', 'brand' => 'Marca Demo', 'model' => 'OP-200', 'part_number' => 'DEMO-OP200', 'price_mode' => 'fixed', 'price' => 489000, 'availability' => 'in_stock', 'compatibility' => "Puertas automáticas de 2 y 4 hojas\nCabinas de hasta 1,10 m de paso libre", 'description' => 'Operador con variador de frecuencia, controlador y correa dentada. Producto de ejemplo para la demostración.'], ['operator-1', 'operator-2', 'operator-3', 'operator-4'], true],
    [$s2, ['name' => 'Botonera de cabina inox 8 paradas', 'category' => 'buttons', 'brand' => 'Marca Demo', 'model' => 'BC-8', 'part_number' => 'DEMO-BC8', 'price_mode' => 'fixed', 'price' => 215000, 'availability' => 'to_order', 'compatibility' => 'Cabinas con panel frontal de 30 cm', 'description' => 'Botonera de acero inoxidable con display. Producto de ejemplo.'], ['panel-1', 'panel-2', 'panel-3'], false],
    [$s1, ['name' => 'Contactor 3 polos 18 A', 'category' => 'electrical', 'brand' => 'Marca Demo', 'model' => 'C-18', 'part_number' => 'DEMO-C18', 'price_mode' => 'fixed', 'price' => 38500, 'availability' => 'in_stock'], ['contactor-1', 'contactor-2'], false],
    [$s3, ['name' => 'Placa de maniobra microprocesada', 'category' => 'electrical', 'brand' => 'Marca Demo', 'model' => 'PM-16', 'part_number' => 'DEMO-PM16', 'price_mode' => 'quote', 'availability' => 'to_order', 'compatibility' => 'Ascensores de hasta 16 paradas'], ['board-1'], false],
    [$s2, ['name' => 'Pulsador antivandálico con braille', 'category' => 'buttons', 'brand' => 'Marca Demo', 'model' => 'PA-1', 'price_mode' => 'fixed', 'price' => 18900, 'availability' => 'in_stock'], ['pushbutton-1', 'pushbutton-2'], true],
    [$s3, ['name' => 'Freno electromagnético para máquina', 'category' => 'machine', 'brand' => 'Marca Demo', 'model' => 'FE-40', 'price_mode' => 'quote', 'availability' => 'to_order'], ['brake-1'], false],
    [$s1, ['name' => 'Cable de acero 8 mm (por metro)', 'category' => 'ropes', 'brand' => 'Marca Demo', 'price_mode' => 'quote', 'availability' => 'to_order', 'compatibility' => 'Máquinas con polea para cable de 8 mm'], ['cable-1'], false],
    [$s2, ['name' => 'Limitador de velocidad', 'category' => 'safety', 'brand' => 'Marca Demo', 'model' => 'LV-1', 'price_mode' => 'fixed', 'price' => 342000, 'availability' => 'in_stock'], ['governor-1'], false],
    [$s3, ['name' => 'Rodamiento de polea tractora', 'category' => 'machine', 'brand' => 'Marca Demo', 'part_number' => 'DEMO-6310', 'price_mode' => 'fixed', 'price' => 62000, 'availability' => 'in_stock'], ['bearing-1'], false],
    [$s3, ['name' => 'Fuente de alimentación 24 V', 'category' => 'electrical', 'brand' => 'Marca Demo', 'model' => 'F-24', 'price_mode' => 'fixed', 'price' => 54800, 'availability' => 'in_stock'], ['psu-1'], false],
    [$s2, ['name' => 'Patín de guía de cabina', 'category' => 'cabin', 'brand' => 'Marca Demo', 'part_number' => 'DEMO-PG1', 'price_mode' => 'fixed', 'price' => 27400, 'availability' => 'in_stock'], ['shoe-1'], false],
    [$s1, ['name' => 'Cortina de luz para puertas', 'category' => 'doors', 'brand' => 'Marca Demo', 'price_mode' => 'quote', 'availability' => 'in_stock'], ['curtain-1'], false],
];
foreach ($rows as $i => [$s, $data, $photos, $featured]) {
    $p = new SupplierProduct($data);
    $p->forceFill(['supplier_id' => $s->id, 'currency' => 'ARS', 'status' => 'published', 'published_at' => now()->subMinutes($i), 'featured_until' => $featured ? now()->addMonth() : null])->save();
    foreach ($photos as $n => $file) {
        $tmp = tempnam(sys_get_temp_dir(), 'demo').'.jpg';
        copy($IMG.'/'.$file.'.jpg', $tmp);
        $stored = app(PhotoProcessor::class)->store($tmp, 'marketplace/'.$s->id.'/'.$p->id);
        $p->photos()->create(['supplier_id' => $s->id, 'path' => $stored['path'], 'thumb_path' => $stored['thumb_path'], 'position' => $n + 1]);
    }
}
echo "ok\n";
