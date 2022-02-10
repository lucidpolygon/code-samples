
<?php

/*
This is a part of the software that does full-scale ERP functions such as Invoices, Bills, Returns, Taxes, and accounting.
This trait is supposed perform dynamic actions like adding additional lines, fetching requires settings and accounts etc.
*/

namespace App\Http\Livewire\Documents\Traits;

use App\Models\Tax;
use App\Models\Item;
use App\Models\Account;
use App\Models\Contact;
use App\Models\Setting;
use Illuminate\Support\Facades\Config;

trait WithDynamicForm
{
    public function getSettings($doc)
    {
        $settings = Setting::where('module', 'Accounting')
            ->whereIn('setting', ['discount_and_tax', 'tax_method', 'currency'])
            ->orwhereIn('module', [$doc])
            ->get(['setting', 'value'])
            ->toArray();
        foreach ($settings as $key => $value) {
            $this->settings[$value['setting']] = $value['value'];
        }
        $this->settings['decimal'] = Config::get('accounting.decimal');
        $this->settings['document'] = $doc;
    }

    public function getLists($doc)
    {
        $this->taxList = Tax::get(['id', 'name', 'rate', 'is_default']);
        $this->lists['taxMethods'] = ['Exclusive', 'Inclusive', 'No Tax'];
        $this->lists['taxes'] = $this->taxList->toArray();

        if ($doc == "Bill") {

            $accounts = Account::whereIn(
                'group',
                ['Current Asset', 'Non-current Asset', 'Direct Cost', 'Other Expense']
            )
                ->orWhere('default_account', 'accounts_payable')
                ->orWhere('default_account', 'rounding_difference')
                ->get();
            $this->settings['default_line_account'] = $accounts->where('default_account', 'inventory')->first()->id;
        }
        if ($doc == "Invoice" || $doc == "Quotation") {
            $accounts = Account::whereIn('group', ['Current Liability', 'Non-current Liability', 'Income', 'Other Income'])
                ->orWhere('default_account', 'accounts_receivable')
                ->orWhere('default_account', 'inventory')
                ->orWhere('default_account', 'discount_allowed')
                ->orWhere('default_account', 'rounding_difference')
                ->get();
            $this->settings['default_line_account'] = $accounts->where('default_account', 'revenue')->first()->id;
        }
        $this->lists['accounts'] = $accounts;
    }

    public function assignTax()
    {
        $this->settings['discount_and_tax'] == "Total"
            ? $tax = $this->taxList->where('is_default', 1)->first()->id
            : $tax = null;
        return $tax;
    }

    public function updatedHeaderDate()
    {
        $this->header['due_date'] =  $this->calculateDueDate();
    }

    public function calculateDueDate()
    {
        $date = date('Y-m-d', strtotime(str_replace('/', '-', $this->header['date'])));
        $days = $this->settings['default_due_date_period'];
        return  date('d/m/Y', strtotime($date . ' +' . $days . ' days'));
    }

    public function updatedSearchContact()
    {
        $this->header['contact_id'] = null;
        $this->header['contact_name'] = null;
        $this->reset('searchContactResults');
        if (strlen($this->searchContact) > 0) {
            $this->searchContactResults = Contact::search($this->searchContact)->get(['id', 'name'])->take(5);
        }
        if (count($this->searchContactResults) == 0) {
            $this->emit('suggestedNewContact', $this->searchContact);
        }
    }

    public function selectContact($id)
    {
        $name = $this->searchContactResults->find($id)->name;
        $this->header['contact_id'] = $id;
        $this->header['contact_name'] = $name;
        $this->searchContact = $name;
        $this->reset('searchContactResults');
    }

    //listening to contact creation via Livewire/Contacts/SimpleForm
    public function contactCreated($contact)
    {
        $this->header['contact_id'] = $contact[0];
        $this->header['contact_name'] = $contact[1];
        $this->searchContact = $contact[1];
    }

    public function updatedSearchItem()
    {
        $this->reset('searchItemResults');
        if (strlen($this->searchItem) > 0) {
            if ($this->settings['document'] == "Invoice" || $this->settings['document'] == "Quotation") {
                $this->searchItemResults = Item::search($this->searchItem)->get(['id', 'sku', 'title', 'selling_price'])->take(5);
            }
            if ($this->settings['document'] == "Bill") {
                $this->searchItemResults = Item::search($this->searchItem)->hasCost()->get(['id', 'sku', 'title', 'selling_price'])->take(5);
            }
        }
        if (count($this->searchItemResults) == 0) {
            $this->emit('suggestedNewItem', $this->searchItem);
        }
    }

    public function selectItem($id)
    {
        $item = $this->searchItemResults->find($id);
        $this->searchItem = $item->name;
        $this->addNewLine($item);
        $this->reset('searchItemResults');
    }

    public function assignLineTax()
    {
        $this->header['discount_and_tax'] == "Line"
            ? $tax = $this->taxList->where('is_default', 1)->first()->id
            : $tax = null;
        return $tax;
    }

    public function addNewLine($item)
    {
        $this->lines[] = [
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->title,
            'rate' => $this->settings['document'] <> "Bill" ? $item->selling_price : '',
            'line_total' => $this->settings['document'] <> "Bill" ? $item->selling_price : 0,
            'qty' => 1,
            'discount' => null,
            'discount_value' => 0,
            'tax_id' => $this->assignLineTax(),
            'tax_details' => null,
            'tax_value' => 0,
            'account_id' => $this->settings['document'] == "Invoice" ? $item->sales_account_id : $this->settings['default_line_account'],
        ];
        $this->linesDirty = true;
    }

    public function addBlankLine()
    {
        $this->lines[] = [
            'item_id' => null,
            'sku' => null,
            'description' => '',
            'qty' => '',
            'rate' => '',
            'line_total' => 0,
            'discount' => null,
            'discount_value' => 0,
            'tax_id' => $this->assignLineTax(),
            'tax_details' => null,
            'tax_value' => 0,
            'account_id' => $this->settings['default_line_account'],
        ];
        $this->dirty = true;
    }

    public function removeLine($id)
    {
        unset($this->lines[$id]);
        $this->dirty = true;
        $this->lines = array_values($this->lines);
    }
}
