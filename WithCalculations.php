<?php

/*
This is a part of the software that does full-scale ERP functions such as Invoices, Bills, Returns, Taxes, and accounting.
This trait is supposed or perform line calculations and total calculations.
Calculations depends on factors such as tax inclusive or exclusive, calculation at line level or invoice level, etc.
*/

namespace App\Http\Livewire\Documents\Traits;

trait WithCalculations
{
    public $loopTotalDisc;
    public $loopGrossTotal;
    public $loopTotalTax;

    public function isNumber($field, $index)
    {
        is_numeric($this->lines[$index][$field])
            ? $this->lines[$index][$field] = round($this->lines[$index][$field], $this->settings['decimal'])
            : $this->lines[$index][$field] = null;
        return $this->lines[$index][$field];
    }

    public function calclateLineDiscount($index, $lineSubTotal)
    {
        $discValue = 0;
        $percent = false;

        $num = $this->lines[$index]['discount'];

        if (substr($num, -1) == "%") {
            $num = substr($num, 0, -1);
            $percent = true;
        }

        if (is_numeric($num)) {
            $num = round($num, $this->settings['decimal']);
            $discValue = $num;

            if ($percent) {
                $discValue = $lineSubTotal * $num / 100;
                $this->lines[$index]['discount'] = $num . '%';
            } else {
                $this->lines[$index]['discount'] = $num;
            }
        } else {
            $this->lines[$index]['discount'] = null;
        }

        $discountAndTax = $this->header['discount_and_tax'];
        $discountAndTax == "Line"
            ? $discValue = round($discValue, $this->settings['decimal'])
            : $discValue = 0;

        $this->lines[$index]['discount_value'] = $discValue;
        return $discValue;
    }

    public function calculateLineTotal($index)
    {
        $qty = $this->isNumber('qty', $index);
        $rate = $this->isNumber('rate', $index);
        $qty * $rate <> 0
            ? $discValue = $this->calclateLineDiscount($index, $qty * $rate)
            : $discValue = 0;
        $this->lines[$index]['line_total'] = round(($qty * $rate) - $discValue, $this->settings['decimal']);
        $this->calculateLineTax($index);
    }

    public function calculateLineTax($index)
    {
        $taxMethod = $this->header['tax_method'];
        $discountAndTax = $this->header['discount_and_tax'];

        $taxIndex = array_search($this->lines[$index]['tax_id'], array_column($this->lists['taxes'], 'id'));
        $tax = $this->lists['taxes'][$taxIndex];
        $taxRate = $tax['rate'];
        $taxValue = 0;

        $total = $this->lines[$index]['line_total'];

        if ($total <> 0) {
            if ($discountAndTax == "Line") {
                if ($taxMethod == "Inclusive") {
                    $taxValue = ($total / (100 + $taxRate)) * $taxRate;
                }

                if ($taxMethod == "Exclusive") {
                    $taxValue = ($total / 100) * $taxRate;
                }
            }
        }

        $this->lines[$index]['tax_details'] = $tax;
        $this->lines[$index]['tax_value'] = round($taxValue, $this->settings['decimal']);
    }

    public function loop()
    {
        $gt = 0;
        $disc = 0;
        $tax = 0;
        foreach ($this->lines as $index => $row) {
            $disc += $row['discount_value'];
            $gt += $row['line_total'];
            $tax += $row['tax_value'];
        }
        $this->loopGrossTotal = $gt;
        $this->loopTotalDisc = $disc;
        $this->loopTotalTax = $tax;
    }

    public function calculateNetTotal()
    {
        $discountAndTax = $this->header['discount_and_tax'];
        $this->loop();

        $this->header['discount_total'] = 0;
        $this->header['tax_total'] = 0;

        if ($discountAndTax == 'Line') {
            $this->header['gross_total'] = $this->loopGrossTotal + $this->loopTotalDisc;
            if ($this->header['gross_total'] <> 0) {
                $this->header['discount_total'] = $this->loopTotalDisc;
                $this->header['tax_total'] = $this->loopTotalTax;
                $this->taxBreakdown = $this->calculateTaxBreakdown();
            }
        } else {
            $this->header['gross_total'] = $this->loopGrossTotal;
            if ($this->header['gross_total'] <> 0) {
                $this->header['discount_total'] = $this->calculateTotalDiscount($this->header['discount'], $this->header['gross_total']);
                $this->header['tax_total'] = $this->calculateTotalTax($this->header['gross_total'] - $this->header['discount_total']);
                if ($this->header['tax_total'] > 0) {
                    $this->taxBreakdown = [];
                    $name = $this->header['tax_details']['name'] . " (" . $this->header['tax_details']['rate'] . "%)";
                    $this->taxBreakdown[$name] =  $this->header['tax_total'];
                }
            }
        }

        $this->header['net_total'] = $this->header['gross_total'] - $this->header['discount_total'] + ($this->header['tax_method'] == "Inclusive" ? 0 : $this->header['tax_total']);
    }


    public function calculateTotalDiscount($totalDiscount, $grossTotal)
    {
        $discValue = 0;
        $percent = false;
        $num = $totalDiscount;

        if (substr($num, -1) == "%") {
            $num = substr($num, 0, -1);
            $percent = true;
        }

        if (is_numeric($num)) {
            $num = round($num, $this->settings['decimal']);
            $discValue = $num;

            if ($percent) {
                $discValue = ($grossTotal / 100) * $num;
                $this->header['discount'] = $num . '%';
            } else {
                $this->header['discount'] = $num;
            }
        } else {
            $this->header['discount'] = null;
        }

        $this->header['discount_total'] = round($discValue, $this->settings['decimal']);
        return round($discValue, $this->settings['decimal']);
    }

    public function calculateTotalTax($grossTotal)
    {
        $taxMethod = $this->header['tax_method'];
        $taxIndex = array_search($this->header['tax_id'], array_column($this->lists['taxes'], 'id'));
        $tax = $this->lists['taxes'][$taxIndex];

        $taxRate = $tax['rate'];
        $taxValue = 0;

        if ($taxRate == 0) {
            return 0;
        }

        if ($taxMethod == "Inclusive") {
            $taxValue = ($grossTotal / (100 + $taxRate)) * $taxRate;
        }
        if ($taxMethod == "Exclusive") {
            $taxValue = ($grossTotal / 100) * $taxRate;
        }
        $this->header['tax_details'] = $tax;
        $this->header['tax_total'] = round($taxValue, $this->settings['decimal']);
        return round($taxValue, $this->settings['decimal']);
    }

    public function calculateTaxBreakdown()
    {
        $unique = [];
        $taxBreakdown = [];
        foreach ($this->lines as $line) {
            if ($line['tax_value'] <> 0) {
                $name = $line['tax_details']['name'] . '-' . $line['tax_details']['rate'] . '%';
                if (in_array($name, $unique)) {
                    $taxBreakdown[$name] =  $taxBreakdown[$name] + $line['tax_value'];
                } else {
                    $taxBreakdown[$name] =  $line['tax_value'];
                    $unique[] = $name;
                }
            }
        }
        return $taxBreakdown;
    }

    //Triggers

    public function updatedHeaderDiscountAndTax()
    {
        foreach ($this->lines as $index => $line) {
            $this->calculateLineTotal($index);
            $this->calculateLineTax($index);
        }
        if ($this->header['discount_and_tax'] == "Total") {
            $this->header['tax'] = $this->taxList->where('is_default', 1)->first()->id;
        }
    }

    public function updatedHeaderTaxMethod()
    {
        foreach ($this->lines as $index => $line) {
            $this->calculateLineTax($index);
        }
    }
}
