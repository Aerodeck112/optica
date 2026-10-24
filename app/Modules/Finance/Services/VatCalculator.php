<?php

namespace App\Modules\Finance\Services;

use App\Modules\Catalog\Models\VatRate;
use App\Modules\Core\Settings\SettingsRepository;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Calculul TVA pentru documente. Regulile urmăresc EN 16931 / CIUS-RO:
 *  - baza pe cotă = suma valorilor nete ale liniilor din acea cotă;
 *  - TVA pe cotă = baza × cotă, rotunjit la ban (nu suma TVA-urilor pe linii);
 *  - pentru prețuri cu TVA inclus, baza pe cotă se alege astfel încât baza + TVA = suma prețurilor cu TVA,
 *    iar diferența de rotunjire se pune pe linia cu valoarea cea mai mare.
 * Toate sumele sunt în bani (int); cantitățile și prețurile unitare sunt zecimale exacte (brick/math).
 */
class VatCalculator
{
    public function __construct(private readonly SettingsRepository $settings) {}

    /** Cota și categoria pentru un grup, la data documentului (sau „O” dacă firma nu este plătitoare de TVA). */
    public function rateFor(string $group, CarbonInterface $date): array
    {
        if ($this->settings->get('company.vat_payer') === 'no') {
            return ['rate' => '0.00', 'category' => 'O', 'exemption_reason' => 'Furnizor neînregistrat în scopuri de TVA'];
        }
        $vat = VatRate::forGroup($group, $date);
        if (! $vat) {
            throw new RuntimeException("Nu există cotă de TVA valabilă pentru grupa „{$group}” la data ".$date->format('d.m.Y').'.');
        }

        return ['rate' => number_format((float) $vat->rate, 2, '.', ''), 'category' => $vat->category_code, 'exemption_reason' => $vat->exemption_reason];
    }

    /**
     * @param  array<int, array{description:string, quantity:string|int|float, unit_gross_minor?:int, unit_net?:string, vat_rate:string, vat_category:string, vat_exemption_reason?:?string, item_code?:?string, unit_code?:?string, advance_invoice_id?:?int}>  $lines
     * @return array{lines: array<int, array>, breakdown: array<int, array>, net_minor:int, vat_minor:int, rounding_minor:int, gross_minor:int} gross = de plată
     */
    public function calculate(array $lines, bool $pricesIncludeVat): array
    {
        $out = [];
        foreach (array_values($lines) as $i => $line) {
            $qty = BigDecimal::of((string) $line['quantity']);
            $rate = BigDecimal::of((string) $line['vat_rate']);
            if ($pricesIncludeVat) {
                $gross = $qty->multipliedBy((int) $line['unit_gross_minor'])->toScale(0, RoundingMode::HalfUp)->toInt();
                $net = BigDecimal::of($gross)->multipliedBy(100)->dividedBy($rate->plus(100), 0, RoundingMode::HalfUp)->toInt();
            } else {
                $net = $qty->multipliedBy(BigDecimal::of((string) $line['unit_net'])->multipliedBy(100))->toScale(0, RoundingMode::HalfUp)->toInt();
                $gross = null;
            }
            $out[$i] = $line + ['position' => $i + 1, 'line_net_minor' => $net, 'line_gross_target' => $gross];
        }

        // grupare pe (categorie, cotă)
        $groups = [];
        foreach ($out as $i => $line) {
            $key = $line['vat_category'].'|'.$line['vat_rate'];
            $groups[$key][] = $i;
        }

        $breakdown = [];
        $targetTotal = 0;
        foreach ($groups as $key => $indexes) {
            [$category, $rateString] = explode('|', $key);
            $rate = BigDecimal::of($rateString);
            $taxable = array_sum(array_map(fn ($i) => $out[$i]['line_net_minor'], $indexes));

            if ($pricesIncludeVat) {
                $targetGross = array_sum(array_map(fn ($i) => $out[$i]['line_gross_target'], $indexes));
                $targetTotal += $targetGross;
                $taxable = $this->taxableForGross($targetGross, $rate);
                $this->distribute($out, $indexes, $taxable);
            }
            $tax = $this->tax($taxable, $rate);
            $breakdown[] = [
                'category' => $category,
                'rate' => $rateString,
                'taxable_minor' => $taxable,
                'tax_minor' => $tax,
                'exemption_reason' => $out[$indexes[0]]['vat_exemption_reason'] ?? null,
            ];
        }

        foreach ($out as &$line) {
            $rate = BigDecimal::of((string) $line['vat_rate']);
            $line['line_vat_minor'] = $this->tax($line['line_net_minor'], $rate); // informativ pe linie
            $line['line_gross_minor'] = $line['line_gross_target'] ?? ($line['line_net_minor'] + $line['line_vat_minor']);
            $line['unit_price'] = (string) BigDecimal::of($line['line_net_minor'])->dividedBy(100, 4, RoundingMode::HalfUp)
                ->dividedBy(BigDecimal::of((string) $line['quantity']), 4, RoundingMode::HalfUp);
            $line['unit_price_gross_minor'] = $line['unit_gross_minor'] ?? null;
            unset($line['line_gross_target'], $line['unit_gross_minor'], $line['unit_net']);
        }
        unset($line);

        $net = array_sum(array_column($breakdown, 'taxable_minor'));
        $vat = array_sum(array_column($breakdown, 'tax_minor'));
        // când nu există o bază întreagă exactă (ex. 840 lei la 21%), diferența de 1–2 bani devine ajustare de rotunjire,
        // ca totalul de plată să fie exact suma prețurilor afișate clientului
        $rounding = $pricesIncludeVat ? $targetTotal - ($net + $vat) : 0;

        return ['lines' => array_values($out), 'breakdown' => $breakdown, 'net_minor' => $net, 'vat_minor' => $vat, 'rounding_minor' => $rounding, 'gross_minor' => $net + $vat + $rounding];
    }

    private function tax(int $taxableMinor, BigDecimal $rate): int
    {
        return BigDecimal::of($taxableMinor)->multipliedBy($rate)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
    }

    /** Baza T pentru care T + round(T × r) = G (cea mai apropiată, dacă nu există potrivire exactă). */
    private function taxableForGross(int $gross, BigDecimal $rate): int
    {
        $candidate = BigDecimal::of($gross)->multipliedBy(100)->dividedBy($rate->plus(100), 0, RoundingMode::HalfUp)->toInt();
        foreach ([0, -1, 1, -2, 2] as $delta) {
            $t = $candidate + $delta;
            if ($t + $this->tax($t, $rate) === $gross) {
                return $t;
            }
        }

        return $candidate;
    }

    /** Ajustează valorile nete ale liniilor ca suma lor să fie exact baza aleasă (diferența pe linia cea mai mare în valoare absolută). */
    private function distribute(array &$lines, array $indexes, int $taxable): void
    {
        $sum = array_sum(array_map(fn ($i) => $lines[$i]['line_net_minor'], $indexes));
        $difference = $taxable - $sum;
        if ($difference === 0) {
            return;
        }
        usort($indexes, fn ($a, $b) => abs($lines[$b]['line_net_minor']) <=> abs($lines[$a]['line_net_minor']));
        $lines[$indexes[0]]['line_net_minor'] += $difference;
    }
}
