<?php

namespace App\Modules\Catalog\Database\Seeders;

use App\Modules\Catalog\Enums\ItemType;
use App\Modules\Catalog\Listeners\ProvisionCompanyCatalog;
use App\Modules\Catalog\Models\Contact;
use App\Modules\Catalog\Models\Item;
use App\Modules\Catalog\Models\Tax;
use App\Modules\Catalog\Models\Withholding;
use App\Modules\Identity\Database\Seeders\DemoSeeder as IdentityDemoSeeder;
use App\Modules\Identity\Models\Company;
use App\Modules\Reference\Enums\IdentificationType;
use App\Modules\Reference\Enums\OrganizationType;
use App\Modules\Reference\Enums\WithholdingType;
use Illuminate\Database\Seeder;

/**
 * SYNTHETIC DEMO DATA for local development, set in Medellín and its metropolitan area. Every
 * contact, NIT, ID number, email, address and item below is fictitious; names are invented and do
 * not refer to real people or businesses, and phones follow the fictitious (604) 555 01NN pattern.
 * The final consumer and the default taxes and withholdings come from ProvisionCompanyCatalog;
 * Demo Servicios also has a ReteICA, whose example rate of 9.66 per thousand is not the rate of
 * any municipality. The names of the items and of the ReteICA are in the language of the demo
 * (catalog::demo); contacts keep their names in both.
 */
final class DemoSeeder extends Seeder
{
    /**
     * Identification type, number, legal name, trade name, tax scheme, fiscal responsibilities,
     * email, phone, address, municipality, postal code and country.
     */
    private const array CONTACTS = [
        'commerce' => [
            ['31', '990000101', 'Distribuidora Cordillera Plata SAS', 'Cordillera Plata', '01', ['O-13'], 'billing@cordillera-plata.example', '(604) 555 0103', 'Carrera 43A # 1-50', '05001', '050021', 'CO'],
            ['31', '990000102', 'Comercializadora Tamarindo Azul SAS', 'Tamarindo Azul', '01', ['O-15', 'O-23'], 'billing@tamarindo-azul.example', '(604) 555 0104', 'Avenida 6N # 23-40', '76001', '760001', 'CO'],
            ['31', '990000103', 'Talleres Metálicos Arenal Norte SAS', null, 'ZA', ['R-99-PN'], 'billing@arenal-norte.example', '(604) 555 0105', 'Calle 72 # 54-10', '08001', '080001', 'CO'],
            ['31', '990000104', 'Inversiones Faro Andino SAS', 'Faro Andino', '01', ['O-47'], 'billing@faro-andino.example', '(604) 555 0106', 'Carrera 43A # 38 Sur-25', '05266', '055422', 'CO'],
            ['13', '9990000105', 'María Fernanda Rojas Pérez', null, 'ZZ', ['R-99-PN'], 'mf.rojas@mail.example', '(604) 555 0107', 'Calle 145 # 12-30', '11001', '110121', 'CO'],
            ['13', '9990000106', 'Carlos Andrés Gómez Ruiz', null, 'ZZ', ['R-99-PN'], 'ca.gomez@mail.example', '(604) 555 0108', 'Carrera 50A # 45-12', '05360', '055412', 'CO'],
            ['13', '9990000107', 'Laura Valentina Moreno Díaz', null, 'ZZ', ['R-99-PN'], 'lv.moreno@mail.example', '(604) 555 0109', 'Diagonal 50 # 38-20', '05088', '051052', 'CO'],
            ['50', 'US4400000108', 'Harborline Export Partners Inc.', 'Harborline', 'ZZ', ['R-99-PN'], 'billing@harborline.example', '(604) 555 0110', '100 Harbor Street, Suite 200', null, '33131', 'US'],
        ],
        'services' => [
            ['31', '990000201', 'Constructora Valle Alto Sereno SAS', 'Valle Alto', '01', ['O-13', 'O-23'], 'billing@valle-alto.example', '(604) 555 0111', 'Carrera 45 # 70 Sur-30', '05631', '055450', 'CO'],
            ['31', '990000202', 'Laboratorio Ambiental Quebrada Clara SAS', 'Quebrada Clara', '01', ['R-99-PN'], 'billing@quebrada-clara.example', '(604) 555 0112', 'Calle 10 # 40-12', '05001', '050021', 'CO'],
            ['31', '990000203', 'Agroindustrias Pradera Dorada SAS', 'Pradera Dorada', '01', ['O-15'], 'billing@pradera-dorada.example', '(604) 555 0113', 'Calle 37 Sur # 31-15', '05266', '055420', 'CO'],
            ['31', '990000204', 'Transportes Sabana Fría SAS', null, 'ZZ', ['R-99-PN'], 'billing@sabana-fria.example', '(604) 555 0114', 'Carrera 52 # 74-20', '05360', '055410', 'CO'],
            ['13', '9990000205', 'Andrés Felipe Castaño López', null, 'ZZ', ['R-99-PN'], 'af.castano@mail.example', '(604) 555 0115', 'Carrera 70 # 44-10', '05001', '050031', 'CO'],
            ['13', '9990000206', 'Diana Marcela Ospina Vargas', null, 'ZZ', ['R-99-PN'], 'dm.ospina@mail.example', '(604) 555 0116', 'Calle 85 # 15-20', '11001', '110221', 'CO'],
            ['22', '9990000207', 'Lucía Fernández Gil', null, 'ZZ', ['R-99-PN'], 'l.fernandez@mail.example', '(604) 555 0117', 'Carrera 53 # 80-15', '08001', '080020', 'CO'],
            ['31', '990000208', 'Hotel Mirador de las Garzas SAS', 'Mirador de las Garzas', '04', ['R-99-PN'], 'billing@mirador-garzas.example', '(604) 555 0118', 'Calle 9 # 37-50', '05001', '050022', 'CO'],
        ],
    ];

    /** Code, type, unit, unit price and the key of its tax in catalog::defaults.taxes (null means excluded); the name is catalog::demo.items.{code}. */
    private const array ITEMS = [
        'commerce' => [
            ['P-001', 'product', '94', '450000', 'iva_19'],
            ['P-002', 'product', 'PK', '21500', 'iva_19'],
            ['P-003', 'product', '94', '18900', 'iva_5'],
            ['P-004', 'product', 'KGM', '4200', null],
            ['P-005', 'product', 'BX', '26000', 'iva_19'],
            ['P-006', 'product', '94', '89900', 'iva_19'],
            ['P-007', 'product', 'DZN', '9800', 'iva_0'],
            ['P-008', 'product', 'LTR', '12500', 'iva_19'],
            ['S-001', 'service', '94', '12000', 'iva_19'],
            ['S-002', 'service', '94', '22000', 'inc_8'],
        ],
        'services' => [
            ['S-101', 'service', 'HUR', '180000', 'iva_19'],
            ['S-102', 'service', '94', '1200000', 'iva_19'],
            ['S-103', 'service', '94', '650000', 'iva_19'],
            ['S-104', 'service', '94', '4800000', 'iva_19'],
            ['S-105', 'service', 'DAY', '950000', 'iva_19'],
            ['S-106', 'service', 'HUR', '250000.50', 'iva_19'],
            ['S-107', 'service', 'HUR', '120000', null],
            ['S-108', 'service', '94', '38000', 'inc_8'],
            ['P-101', 'product', '94', '35000', 'iva_19'],
            ['P-102', 'product', '94', '42000', 'iva_19'],
        ],
    ];

    public function run(): void
    {
        IdentityDemoSeeder::eachCompany(function (Company $company): void {
            $set = $company->identification_number === IdentityDemoSeeder::NITS[0] ? 'commerce' : 'services';

            foreach (self::CONTACTS[$set] as [$type, $number, $legalName, $tradeName, $taxScheme, $responsibilities, $email, $phone, $address, $municipality, $postalCode, $country]) {
                Contact::create([
                    'organization_type' => in_array($type, [IdentificationType::Nit->value, IdentificationType::ForeignNit->value], true)
                        ? OrganizationType::LegalEntity->value
                        : OrganizationType::NaturalPerson->value,
                    'identification_type' => $type,
                    'identification_number' => $number,
                    'legal_name' => $legalName,
                    'trade_name' => $tradeName,
                    'tax_scheme' => $taxScheme,
                    'fiscal_responsibilities' => $responsibilities,
                    'email' => $email,
                    'phone' => $phone,
                    'address' => $address,
                    'country_code' => $country,
                    'municipality_code' => $municipality,
                    'postal_code' => $postalCode,
                ]);
            }

            if ($set === 'services') {
                Withholding::create(['dian_code' => WithholdingType::ReteIca, 'name' => self::reteIca(), 'rate' => '0.966']);
            }

            $taxes = Tax::query()->pluck('id', 'name');

            foreach (self::ITEMS[$set] as [$code, $type, $unit, $price, $tax]) {
                Item::create([
                    'code' => $code,
                    'name' => (string) __("catalog::demo.items.{$code}"),
                    'type' => ItemType::from($type),
                    'unit_code' => $unit,
                    'unit_price' => $price,
                    'tax_id' => $tax === null ? null : $taxes[ProvisionCompanyCatalog::name("taxes.{$tax}")],
                ]);
            }
        });
    }

    /** The name of the ReteICA of Demo Servicios, in the language of the demo: its rate is stored as a percentage (9.66 per thousand). */
    public static function reteIca(): string
    {
        return (string) __('catalog::demo.rete_ica');
    }
}
