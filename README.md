# PHP Nacha Engine
Nacha File Generator for PHP

I wrote this after not finding an open source PHP Nacha file generator that met my needs.  There is another project (https://github.com/snsparrish/NachaLibPHP) that is similar, but it doesn't support balanced (offset) transactions, multiple batches per file, aba validation, etc.

The output has been vetted by my bank and was approved for production use (this in no way is a guarantee of anything).

Example usage:

```php
include_once 'nacha_engine.class.php';

                        //$origin_id, $company_id, $company_name, $settlement_routing_number, $settlement_account_number, $originating_bank_name, $balanced_file = false, $settlement_is_savings = false, $file_modifier = 'A'
$nacha = new nacha_file('026009593', '8433172501', 'Your Name', '051000017', '6050786234987', 'Bank of America', true);

                          //$amount, $name, $bank_routing_to, $bank_account_to, $memo, $internal_id, $create_new_batch = true, $savings_account = false, $personal_payment = false
$nacha->create_credit_entry(5623.57, "Big Bob's Speaker City", '121000358', '0912346719578', 'Refund', 100996);

$nacha->create_credit_entry(7896.64, 'Gonzalez Tacos', '026009593', '0921550410536', 'Catering', 100894);

$nacha->create_credit_entry(11264.14, 'Lake Shasta Autos', '111000025', '0898963213', 'Loan Fund', 100997);

//Handle your errors
if(!empty($nacha->errors)){ 
    print_r($nacha->errors);
    exit;
}

//Do what you want with the Nacha file string
echo $nacha->get_file_string();
```
The above example is for a credit (give money to someone) file.  It is the same for debits, just change create_credit_entry to create_debit_entry.  It will allow you to mix credits and debits, but you need to check with your bank.

I recommend you read the Nacha Operating Rules prior to implementation.
