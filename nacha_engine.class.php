<?php
/**
 *  Copyright (C) 2015 Louis Beyer (https://github.com/PartyTimeLou/)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program.  If not, see <http://www.gnu.org/licenses/>. 
 */

class nacha_file
{
    const BLOCKING_FACTOR               = 10;
    
    private $file_header                = '';
    private $batch_string               = '';
    private $file_footer                = '';
    
    
    private $open_file                  = false;
    private $open_batch                 = false;
    
    private $line_count                 = 0;
    private $entry_count                = 0;
    private $total_debits               = 0;
    private $total_credits              = 0;
    
    private $number_of_batches          = 0;
    private $batches                    = array();
    
    
    private $balanced_file              = false;
    
    private $company_name               = '';
    private $company_id                 = '';
    private $settlement_routing_number  = '';
    private $settlement_account_number  = '';
    private $originating_bank_name      = '';
    
    //Can only 
    private $file_type                  = '';
    public  $errors                     = array();
    
    
    public function __construct($origin_id, $company_id, $company_name, $settlement_routing_number, $settlement_account_number, $originating_bank_name, $balanced_file = false, $settlement_is_savings = false, $file_modifier = 'A') 
    {
        //ACH OR74
        if(!$this->numeric_only($origin_id, 10)) $this->errors[] = 'Invalid origin ID';
        
        if(!$this->valid_aba($settlement_routing_number)) $this->errors[] = 'Invalid settlement routing number';
        
        if(!$this->alpha_numeric_only($file_modifier, 1)) $this->errors[] = 'Invalid file modifier';
        
        if(!$this->alphameric_only($originating_bank_name, 23)) $this->errors[] = 'Invalid originating bank name';
        
        if(!$this->alphameric_only($company_name, 23)) $this->errors[] = 'Invalid company name';
        
        if(!empty($this->errors)) return false;
        
        $this->file_header =    '1'.
                                '01'.
                                ' '.$this->format_number($settlement_routing_number, 9).
                                ' '.$this->format_number($origin_id, 9).
                                date('ymd').
                                date('Hi').
                                $this->format_text($file_modifier, 1).
                                '094'.
                                '10'.
                                '1'.
                                $this->format_text($originating_bank_name, 23).
                                $this->format_text($company_name, 23).
                                $this->format_text('', 8).
                                "\n";
        $this->line_count++;   
        
        $this->company_name = $company_name;
        $this->company_id = $company_id;   
        $this->settlement_routing_number = $settlement_routing_number;  
        $this->settlement_account_number = $settlement_account_number;  
        $this->originating_bank_name = $originating_bank_name;
        $this->balanced_file = $balanced_file;
        
        $this->open_file = true;             
    }
    
    private function create_batch($memo, $personal_payment = false, $service_class_code = '200')
    {
        if(!$this->open_file){
            $this->errors[] = 'No open file to create batch';
            return false;            
        }
       
        if(!$this->numeric_only($service_class_code, 3)){
            $this->errors[] = 'Invalid service class code for batch creation';
            return false;
        }
        
        if(!$this->alphameric_only($memo, 10)){
            $this->errors[] = 'Invalid memo (Company Entry Description) for batch creation';
            return false;
        }
                
        if($this->open_batch) $this->close_batch($this->number_of_batches);
        else $this->open_batch = true;
        
        $this->number_of_batches++;
         
        $batch_header =     '5'.
                            $this->format_number($service_class_code, 3).
                            $this->format_text($this->company_name, 16).
                            $this->format_text('', 20).
                            $this->format_text($this->company_id, 10).
                            ($personal_payment?'PPD':'CCD').
                            $this->format_text($memo, 10).
                            $this->format_text('', 6).
                            $this->format_text(date('ymd'), 6).
                            $this->format_text('', 3).
                            '1'.
                            $this->format_text($this->settlement_routing_number, 8).
                            $this->format_number($this->number_of_batches, 7);
        
        $this->batches[$this->number_of_batches]['scc']     = $service_class_code;
        $this->batches[$this->number_of_batches]['header']  = $batch_header;
        $this->batches[$this->number_of_batches]['entries'] = array();
        $this->batches[$this->number_of_batches]['debits']  = 0;
        $this->batches[$this->number_of_batches]['credits'] = 0;
        $this->batches[$this->number_of_batches]['hash']    = 0;
        
        $this->line_count++;
    }
    
    // Takes money from someone else's account and puts it in yours 
    public function create_debit_entry($amount, $name, $bank_routing_to, $bank_account_to, $memo, $internal_id, $create_new_batch = true, $savings_account = false, $personal_payment = false)
    {   
        if(!$this->valid_aba($bank_routing_to)) $this->errors[] = 'Invalid debit routing number for transaction: '.$internal_id; 
        if(!$this->numeric_only($bank_account_to, 17)) $this->errors[] = 'Invalid debit account number for transaction: '.$internal_id;
        if(!$this->alphameric_only($internal_id, 15)) $this->errors[] = 'Invalid internal ID for transaction: '.$internal_id;
        if(!$this->alphameric_only($name, 22)) $this->errors[] = 'Invalid name for debit entry for transaction: '.$internal_id;
        
        if(!$this->valid_decimal($amount)) $this->errors[] = 'Invalid debit amount for transaction: '.$internal_id;
        
        if(!empty($this->errors)) return false;
                
        if($create_new_batch) $this->create_batch($memo, $personal_payment);
        elseif(!$create_new_batch && !$this->open_batch){
            $this->errors[] = 'Cannot append to unopen batch for transaction: '.$internal_id;
            return false;
        }
        
        if(!empty($this->errors)) return false;

        $this->entry_count++;
        
        //ACH Rules OR94
        //Transaction code: 22 checking credit, 27 checking debit
        //Transaction code: 32 savings credit, 37 savings debit                
        $this->batches[$this->number_of_batches]['entries'][$this->entry_count] =    '6'.
                                                                            ($savings_account?'37':'27').
                                                                            $this->format_number($bank_routing_to, 9). //OR94 04-11 - we are going to do 4-12 and omit 12
                                                                            ''. //Ommited "check digit"
                                                                            $this->format_text($bank_account_to, 17).
                                                                            $this->format_number($amount*100, 10).
                                                                            $this->format_text($internal_id, 15).
                                                                            $this->format_text($name, 22).
                                                                            $this->format_text('', 2).
                                                                            '0'.
                                                                            substr($this->settlement_routing_number,0,8).$this->format_number($this->entry_count, 7).
                                                                            "\n";
        $this->batches[$this->number_of_batches]['debits'] += $amount*100;
        $this->batches[$this->number_of_batches]['hash'] +=  substr($bank_routing_to, 0, 8); //Not clearly documented, but only first 8 digits used in hash sum
        
        $this->line_count++;
                
        if($this->balanced_file){
            $this->entry_count++;
            $this->batches[$this->number_of_batches]['entries'][$this->entry_count] =    '6'.
                                                                                ($savings_account?'32':'22').
                                                                                $this->format_number($this->settlement_routing_number, 9). //OR94 04-11 - we are going to do 4-12 and omit 12
                                                                                ''. //Ommited "check digit"
                                                                                $this->format_text($this->settlement_account_number, 17).
                                                                                $this->format_number($amount*100, 10).
                                                                                $this->format_text($internal_id, 15).
                                                                                $this->format_text($this->company_name, 22).
                                                                                $this->format_text('', 2).
                                                                                '0'.
                                                                                substr($this->settlement_routing_number,0,8).$this->format_number($this->entry_count, 7).
                                                                                "\n"; 
            $this->batches[$this->number_of_batches]['credits'] += $amount*100;   
            $this->batches[$this->number_of_batches]['hash'] += substr($this->settlement_routing_number, 0, 8); //Not clearly documented, but only first 8 digits used in hash sum
            
            $this->line_count++;
        }                                                                           
    }
    
    // Takes money from your account and puts it into someone else's
    public function create_credit_entry($amount, $name, $bank_routing_to, $bank_account_to, $memo, $internal_id, $create_new_batch = true, $savings_account = false, $personal_payment = false)
    {   
        if(!$this->valid_aba($bank_routing_to)) $this->errors[] = 'Invalid credit routing number for transaction: '.$internal_id; 
        if(!$this->numeric_only($bank_account_to, 17)) $this->errors[] = 'Invalid credit account number for transaction: '.$internal_id;
        if(!$this->alphameric_only($internal_id, 15)) $this->errors[] = 'Invalid internal ID for transaction: '.$internal_id;
        if(!$this->alphameric_only($name, 22)) $this->errors[] = 'Invalid name for credit entry for transaction: '.$internal_id;
        
        if(!$this->valid_decimal($amount)) $this->errors[] = 'Invalid credit amount for transaction: '.$internal_id;
        
        if(!empty($this->errors)) return false;
        
        if($create_new_batch) $this->create_batch($memo, $personal_payment);
        elseif(!$create_new_batch && !$this->open_batch){
            $this->errors[] = 'Cannot append to unopen batch for transaction: '.$internal_id;
            return false;
        }
        
        if(!empty($this->errors)) return false;
        
        $this->entry_count++;

        //ACH Rules OR94
        //Transaction code: 22 checking credit, 27 checking debit
        //Transaction code: 32 savings credit, 37 savings debit                        
        $this->batches[$this->number_of_batches]['entries'][$this->entry_count] =    '6'.
                                                                            ($savings_account?'32':'22').
                                                                            $this->format_number($bank_routing_to, 9). //OR94 04-11 - we are going to do 4-12 and omit 12
                                                                            ''. //Ommited "check digit"
                                                                            $this->format_text($bank_account_to, 17).
                                                                            $this->format_number($amount*100, 10).
                                                                            $this->format_text($internal_id, 15).
                                                                            $this->format_text($name, 22).
                                                                            $this->format_text('', 2).
                                                                            '0'.
                                                                            substr($this->settlement_routing_number,0,8).$this->format_number($this->entry_count, 7).
                                                                            "\n";
        $this->batches[$this->number_of_batches]['credits'] += $amount*100;
        $this->batches[$this->number_of_batches]['hash'] += substr($bank_routing_to, 0, 8); //Not clearly documented, but only first 8 digits used in hash sum
        
        $this->line_count++;
        
        if($this->balanced_file){
            $this->entry_count++;
            $this->batches[$this->number_of_batches]['entries'][$this->entry_count] =    '6'.
                                                                                ($savings_account?'37':'27').
                                                                                $this->format_number($this->settlement_routing_number, 9). //OR94 04-11 - we are going to do 4-12 and omit 12
                                                                                ''. //Ommited "check digit"
                                                                                $this->format_text($this->settlement_account_number, 17).
                                                                                $this->format_number($amount*100, 10).
                                                                                $this->format_text($internal_id, 15).
                                                                                $this->format_text($this->company_name, 22).
                                                                                $this->format_text('', 2).
                                                                                '0'.
                                                                                substr($this->settlement_routing_number,0,8).$this->format_number($this->entry_count, 7).
                                                                                "\n"; 
            $this->batches[$this->number_of_batches]['debits'] += $amount*100; 
            $this->batches[$this->number_of_batches]['hash'] += substr($this->settlement_routing_number, 0, 8); //Not clearly documented, but only first 8 digits used in hash sum
              
            $this->line_count++;
        }                                                                           
    }

    //OR75
    private function close_batch($batch_number)
    {
        if(!$this->open_batch){
            $this->errors[] = 'No open batch to close';
            return false;
        }
        
        if(empty($this->batches[$batch_number]['entries'])){
            $this->errors[] = 'No entries in batch on close';
            return false;            
        }
        
        $batch_footer =     '8'.
                            $this->format_number($this->batches[$batch_number]['scc'], 3).
                            $this->format_number(count($this->batches[$batch_number]['entries']), 6).
                            $this->format_number($this->batches[$batch_number]['hash'], 10).
                            $this->format_number($this->batches[$batch_number]['debits'], 12).
                            $this->format_number($this->batches[$batch_number]['credits'], 12).
                            $this->format_text($this->company_id, 10).
                            $this->format_text('', 19).
                            $this->format_text('', 6).
                            $this->format_text($this->settlement_routing_number, 8).
                            $this->format_number($batch_number, 7);
        
        $this->line_count++;                   
        $this->batches[$batch_number]['footer'] = $batch_footer;
    }
    
    private function close_file()
    {
        if(!$this->open_file){
            $this->errors[] = 'No open file to close';
            return false;            
        }        
        
        if($this->open_batch) $this->close_batch($this->number_of_batches);
        
        $entry_hash = 0;
        
        foreach($this->batches as $batch){
            $this->batch_string .= $batch['header']."\n";
            
            foreach($batch['entries'] as $entry) $this->batch_string .= $entry;
            
            $this->batch_string .= $batch['footer']."\n";
            
            $this->total_debits += $batch['debits'];
            $this->total_credits += $batch['credits'];
            
            $entry_hash += $batch['hash'];
        }
        
        $this->line_count++;
        
        $block_count = ceil(($this->line_count)/self::BLOCKING_FACTOR);
        $fill_lines_needed = ($block_count*self::BLOCKING_FACTOR)-$this->line_count;

        $this->file_footer =    '9'.
                                $this->format_number(count($this->batches), 6).
                                $this->format_number($block_count, 6).
                                $this->format_number($this->entry_count, 8).
                                $this->format_number($entry_hash, 10).
                                $this->format_number($this->total_debits, 12).
                                $this->format_number($this->total_credits, 12).
                                $this->format_text('', 39).
                                "\n";
                                
        for($inserted_lines = 0; $inserted_lines < $fill_lines_needed; $inserted_lines++)
            $this->file_footer .= str_pad('', 94,'9')."\n"; 
            
        $this->open_file = false;                               
    }
    
    public function get_file_string()
    {
        if(!empty($this->errors)) return false;
        
        $this->close_file();
        
        $file_sting = $this->file_header;
        $file_sting .= $this->batch_string;
        $file_sting .= $this->file_footer;
        
        return $file_sting;
    }
    
    /**
     * Utility functions from here on
     * 
     */
    
    function valid_aba($aba_number)
    {
        $aba_length = strlen($aba_number);
        
        if(!$this->numeric_only($aba_number, 9) || $aba_length != 9) return false;
        
        $check_sum = 0;

        for ($position = 0; $position < $aba_length; $position += 3 ){
            
            $check_sum += ($aba_number[$position]*3);
            $check_sum += ($aba_number[$position+1]*7);
            $check_sum += ($aba_number[$position+2]);
        }
                   
        if($check_sum != 0 && ($check_sum % 10) == 0) return true;
        else return false;
    }    
    
    private function format_text($text, $number_of_spaces_to_insert){
        return substr(str_pad(strtoupper($text), $number_of_spaces_to_insert, ' ', STR_PAD_RIGHT), 0, $number_of_spaces_to_insert);
    }

    private function format_number($number, $number_of_spaces_to_insert){
        return substr(str_pad(str_replace(array('.',','), '', (string)$number), $number_of_spaces_to_insert, '0', STR_PAD_LEFT), ($number_of_spaces_to_insert)*-1);
    }
    
    private function alpha_only($string, $length)
    {
        if(!ctype_alpha($string)) return false;
        if(strlen($string) > $length) return false;
        
        return true;
    }
    
    private function alphameric_only($string, $length)
    {
        if(!ctype_print($string)) return false;
        if(strlen($string) > $length) return false;
        
        return true;
    }
    
    private function numeric_only($string, $length)
    {
        if(!ctype_digit((string)$string)) return false;
        if(strlen($string) > $length) return false;
        
        return true;
    }        
    
    private function alpha_numeric_only($string, $length)
    {
        if(!ctype_alnum($string)) return false;
        if(strlen($string) > $length) return false;
        
        return true;
    }    

    private function valid_decimal($number) 
    {
        return !(preg_match('/\.[0-9]{2,}[1-9][0-9]*$/', (string)$number) > 0);
    }

}

?>
