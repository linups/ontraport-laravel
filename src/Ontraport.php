<?php
namespace Linups\OntraportLaravel;

use Linups\OntraportLaravel\ABSontraport;


use OntraportAPI\Ontraport as APIontraport;
use OntraportAPI\ObjectType;

require_once dirname(__FILE__) . '/vendor/autoload.php';

class Ontraport extends ABSontraport {
    private $client;
    
    public function __construct() {
        $this->client = new APIontraport(config('ONTRAPORT_APP_ID') ?? env('ONTRAPORT_APP_ID'), 
                config('ONTRAPORT_APP_KEY') ?? env('ONTRAPORT_APP_KEY'));
    }
    
    
    private function prepareFields($data) {
        $return = $data;
        if (isset($data['email']) && $data['email'] != '') $return['email'] = str_replace(' ', '', $data['email']);
        if (isset($data['firstname']) && $data['firstname'] != '') $return['firstname'] = $data['firstname'];
        if (isset($data['lastname']) && $data['lastname'] != '') $return['lastname'] = $data['lastname'];
        if (isset($data['billing_address1']) && $data['billing_address1'] != '') $return['address'] = $data['billing_address1'];
        if (isset($data['billing_city']) && $data['billing_city'] != '') $return['city'] = $data['billing_city'];
        if (isset($data['billing_state']) && $data['billing_state'] != '') $return['state'] = $data['billing_state'];
        if (isset($data['billing_zip']) && $data['billing_zip'] != '') $return['zip'] = $data['billing_zip'];
        if (isset($data['cell_phone']) && $data['cell_phone'] != '') $return['cell_phone'] = $data['cell_phone'];
        if (isset($data['billing_address2']) && $data['billing_address2'] != '') $return['address2'] = $data['billing_address2'];
        if (isset($data['billing_country']) && $data['billing_country'] != '') $return['country'] = $data['billing_country'];
        if (isset($data['payment_expire_month']) && $data['payment_expire_month'] != '') $return['ccExpirationMonth'] = $data['payment_expire_month'];
        if (isset($data['payment_expire_year']) && $data['payment_expire_year'] != '') $return['ccExpirationYear'] = $data['payment_expire_year'];
        if (isset($data['ccExpirationDate']) && $data['ccExpirationDate'] != '') $return['ccExpirationDate'] = $data['ccExpirationDate'];
        if (isset($data['payment_number']) && $data['payment_number'] != '') $return['ccNumber'] = $data['payment_number'];
        
        return $return;
    }
    
    public function addUser($data) {
        $requestParams = array(
            "objectID"  => ObjectType::CONTACT, // Object type ID: 0
            "email"     => str_replace(' ', '', $data['email']) // The unique field for the contact object
        );
        $requestParamsMerged = array_merge($requestParams, $this->prepareFields($data));
        $result = json_decode($this->client->object()->saveOrUpdate($requestParamsMerged));
        
        if (!isset($result) || (isset($result) && $result->code != 0)) {
            mail('linas@hardrokas.net', 'Ontraport API v2 error.', '<pre>AddUser:data'.print_r($data, true).
                    '| RequestParams:'.print_r($requestParams, true).
                    '| Merged Params:'.print_r($requestParamsMerged, true).
                    '| Result from server:' .print_r($result, true).'</pre>');
            $result->status = 'fail';
        } else {
            $result->status = 'success';
        }
        return $result;        
    }
    
    public function updateUser($email, $data) {
        $idArray = ['id' => $this->getCustomerID($email)];
        $requestParams = array_merge($idArray, $data);

        return $this->client->contact()->update($requestParams);
    }
    
    private function trasactionType($type) {
        if ($type == 'sale' || $type == 'charge') {
            return 'one_time';
        } elseif($type == 'subscription') {
            return 'payment_plan';
        } elseif($type == 'infinite-subscription') {
            return 'subscription';
        }
    }
    
    public function chargeUser($data) {
        $data = $this->prepareFields($data);
        
        $requestParams = array(
            "contact_id"       => $this->getCustomerID($data['email']), // contact_id
            "chargeNow"        => 'chargeNow',
            "trans_date"       => time(),
            "invoice_template" => 1,
            "gateway_id"       => \config::getConfig()['payment']['gateway_id'], // payment gateway
/*            "offer"            => array(
                "products"          => array(
                    array(
                        "quantity"           => 1,
                        "shipping"           => false,
                        "tax"                => false,
                        "price"              => array(
                            array(
                                "price"             => $data['price'],
                                "payment_count"     => 1,
                                "unit"              => "month",
                                "id"                => 1 // The ID of the pricing item
                                )
                        ),
                        // subscription => a recurring purchase item
                        // one_time => a single purchase item
                        // payment_plan => a product paid for on installment
                        "type"                => $this->trasactionType($data['type']),
                        "owner"               => 1,
                        "offer_to_affiliates" => false,
                        "trial_period_unit"   => "day",
                        "trial_period_count"  => 0,
                        "setup_fee_when"      => "immediately",
                        "setup_fee_date"      => time(), 
                        "delay_start"         => 0,
                        "subscription_count"  => 0,
                        "subscription_unit"   => "month",
                        "taxable"             => false,
                        "id"                  => $data['product_id'] // product id
                        ),
                    )
                ),
            "billing_address"     => array(
                "address"     => $data['address'],
                "city"        => $data['city'],
                "state"       => $data['state'],
                "zip"         => $data['zip'],
                "country"     => $data['country']
                ),
            "payer"              => array(
                "ccnumber"     => $data['ccNumber'],
                "code"         => $data['payment_code'],
                "expire_month" => $data['ccExpirationMonth'],
                "expire_year"  => $data['ccExpirationYear']
                )*/
            );
        //--- Charge array
        (isset($data['contact_id'])) ? $requestParams['contact_id'] = $data['contact_id'] : $requestParams['contact_id'] = $this->getCustomerID($data['email']);
        $requestParams['chargeNow'] = 'chargeNow';
        $requestParams['trans_date'] = time();
        $requestParams['invoice_template'] = 1;
        (isset($data['gateway_id'])) ? $requestParams['gateway_id'] = $data['gateway_id'] : $requestParams['gateway_id'] =  \config::getConfig()['payment']['gateway_id'];
        //--- Offer
            //--- Products
        $requestParams['offer']['products'][0]['quantity'] = 1;
        $requestParams['offer']['products'][0]['shipping'] = false;
        $requestParams['offer']['products'][0]['tax'] = false;
                //--- Price
        $requestParams['offer']['products'][0]['price'][0]['price'] = $data['price'];
        (isset($data['payment_count'])) ? $requestParams['offer']['products'][0]['price'][0]['payment_count'] = $data['payment_count']  : $requestParams['offer']['products'][0]['price'][0]['payment_count'] = 1;
        $requestParams['offer']['products'][0]['price'][0]['unit'] = 'month';
        $requestParams['offer']['products'][0]['price'][0]['id'] = 1;
        
        $requestParams['offer']['products'][0]['type'] = $this->trasactionType($data['type']);
        $requestParams['offer']['products'][0]['owner'] = 1;
        $requestParams['offer']['products'][0]['offer_to_affiliates'] = false;
        $requestParams['offer']['products'][0]['trial_period_unit'] = 'day';
        $requestParams['offer']['products'][0]['trial_period_count'] = 0;
        $requestParams['offer']['products'][0]['setup_fee_when'] = 'immediately';
        $requestParams['offer']['products'][0]['setup_fee_date'] = time();
        $requestParams['offer']['products'][0]['delay_start'] = 0;
        $requestParams['offer']['products'][0]['subscription_count'] = 0;
        $requestParams['offer']['products'][0]['subscription_unit'] = 'month';
        $requestParams['offer']['products'][0]['taxable'] = false;
        $requestParams['offer']['products'][0]['id'] = $data['product_id'];
        //--- Billing Address
        $requestParams['billing_address']['address'] = $data['address'];
        $requestParams['billing_address']['city'] = $data['city'];
        $requestParams['billing_address']['state'] = $data['state'];
        $requestParams['billing_address']['zip'] = $data['zip'];
        $requestParams['billing_address']['country'] = $data['country'];
        // Payer
        $requestParams['payer']['ccnumber'] = $data['ccNumber'];
        $requestParams['payer']['code'] = $data['payment_code'];
        $requestParams['payer']['expire_month'] = $data['ccExpirationMonth'];
        $requestParams['payer']['expire_year'] = $data['ccExpirationYear'];
          
echo("<pre>".print_r($requestParams, true)."</pre>");        
        $result = json_decode($this->client->transaction()->processManual($requestParams));
        
        if (isset($result->code) && $result->code == 0) {
            $result->status = 'success';
        } else {            
            mail('linas@hardrokas.net', 'Ontraport API v2 error.', '<pre>'.print_r($result, true).'</pre>');
            $result->status = 'fail';
            $result->message = $result->chargeResult->message;
        }
        
        return $result;
    
    }
    
    private function getCustomerID($email) { 
        $result = json_decode($this->getContactObj(array('email' => $email))); 
        if (isset($result->code) && $result->code == 0) {
            return $result->data->id;
        } else {
            $newContact = $this->addUser(array('email' => $email));
            return $newContact->data->id;
        }
    }
    
    private function getContactObj($array) { 
        $requestParams = array(
            "objectID" => ObjectType::CONTACT, // Object type ID: 0
            "email"    => $array['email'],
            "all"      => 0
        ); 
        return $this->client->object()->retrieveIdByEmail($requestParams);
    }
    
    public function addTag($tag_ids, $contact_ids) {
        $requestParams = array(
            "objectID" => ObjectType::CONTACT, // Object type ID: 0
            "ids"      => $contact_ids,
            "add_list" => $tag_ids
        );
        return $this->client->object()->addTag($requestParams);
    }
    
    public function addTagByEmail($email, $tags) {
        $contactID = $this->getCustomerID($email);
        if(is_array($tags)) $tags = implode(',',$tags);
        
        return $this->addTag($tags, $contactID);
    }
    
    public function addSequenceByEmail($email, $seq_ids) {
        $contactID = $this->getCustomerID($email);
        if(is_array($seq_ids)) $seq_ids = implode(',',$seq_ids);
        
        return $this->addSequence($seq_ids, $contactID);
    }
    
    public function addSequence($seq_ids, $contact_ids) {
        $requestParams = array(
            "objectID" => ObjectType::CONTACT, // Object type ID: 0
            "ids"      => $contact_ids,
            "add_list" => $seq_ids,
        );

        return $this->client->object()->addToSequence($requestParams);
    }
    
    public function getTransactions($email) {
        $contactID = $this->getCustomerID($email);
        $requestParams = array(
            "objectID" => ObjectType::PURCHASE,
            "condition"      => 'contact_id='.$contactID
        );
        return $this->client->object()->retrieveMultiple($requestParams);
    }
    
    public function logTransaction($email, $data) { 
        $contactID = $this->getCustomerID($email);
    
        $requestParams2 = array(
      "contact_id"       => $contactID,
      "chargeNow"        => "chargeLog",
      "offer"            => array(
          "products"        => array(
              "quantity"  => 1,
            "price" =>    array(
                  "price"  => $data['price'],
                  "id"        => $data['sync_id']
              ),
              "id"        => $data['sync_id']
          )
      )
    );
        
        
            $requestParams = new \stdClass();
            $requestParams->contact_id = $contactID;
            $requestParams->chargeNow = 'chargeLog';
            $requestParams->offer  = new \stdClass();
            $requestParams->offer->products = array();
            $requestParams->offer->products[0] = new \stdClass();
            $requestParams->offer->products[0]->quantity = 1;
            $requestParams->offer->products[0]->price = array();
            $requestParams->offer->products[0]->price[0] = new \stdClass();
            $requestParams->offer->products[0]->price[0]->price = $data['price'];
            $requestParams->offer->products[0]->price[0]->id = $data['sync_id'];
            $requestParams->offer->products[0]->id = $data['sync_id'];
        

    $status = $this->client->transaction()->processManual($requestParams2, $requestParams);

    return $status;
    }
    
    public function getContactList($requestParams) {
        //return $this->client->object()->retrieveMultiplePaginated($requestParams);
        return $this->client->contact()->retrieveMultiple($requestParams);
    }
    
    public function getAffiliateList(array $filter = null) {

/*      $std1 = new \stdClass();
        $std1->value = 30;
        
        $std2 = new \stdClass();
        $std2->value = 35;
        
        $std3 = new \stdClass();
        $std3->value = 41;
                
        $array = [$std1, $std2, $std3];
                
        $filter = new \stdClass();
        $filter->field = new \stdClass();
        $filter->field->field = 'product_id';
        $filter->op = 'IN';
        $filter->value = new \stdClass();
        $filter->value->list = $array;
        
        $filter = json_encode(array($filter));*/
//        dd($filter);
        
        $requestParams = array(
            "objectID"   => ObjectType::COMMISSION, // Object type ID: 0
            "sort"       => "lastname",
            "sortDir"    => "desc",
//            "listFields" => "id,firstname,lastname,email,product_id,commission,date",
        );
        if(!is_null($filter)) $requestParams["condition"] = json_encode($filter);
        
        $response = $this->client->object()->retrieveMultiple($requestParams);
        
        return $response;
        /*$response2 = json_decode($response);
        
        dd($response2->data[0], 
                date('Y-m-d H:i:s', $response2->data[0]->date),
                date('Y-m-d H:i:s', $response2->data[0]->date_processed),
                date('Y-m-d H:i:s', $response2->data[0]->date_paid),
                date('Y-m-d H:i:s', $response2->data[0]->dlm)
                );*/
    }
}
