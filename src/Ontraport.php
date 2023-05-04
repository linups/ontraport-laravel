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
    /** tested **/
    public function addUser($data) {
        $requestParams = array(
            "objectID"  => ObjectType::CONTACT, // Object type ID: 0
            "email"     => str_replace(' ', '', $data['email']) // The unique field for the contact object
        );
        $requestParamsMerged = array_merge($requestParams, $this->prepareFields($data));

        $result = json_decode($this->client->object()->saveOrUpdate($requestParamsMerged));

        if(isset($result->code) && $result->code == 0) return $result;

            throw new \Exception('<pre>' . print_r([
                    'name' => 'addUser',
                    'postData' => $data,
                    'resultData' => $result,
                    'requestParams' => $requestParams,
                    'mergedParams' => $requestParamsMerged,
                ], true) . '</pre>');

    }
    /** tested **/
    public function updateUser($email, $data) {
        $idArray = ['id' => $this->getCustomerID($email)];
        $requestParams = array_merge($idArray, $data);

        $response = json_decode($this->client->contact()->update($requestParams));

        if($response->code == 0) return $response;

        throw new \Exception('<pre>'.print_r([
                'name' => 'updateUser',
                'email' => $email,
                'postData' => $data,
                'resultData' => $response,
                'idArray' => $idArray,
                'requestParams' => $requestParams,
            ], true).'</pre>');
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
    
    
    private function getCustomerID($email) { 
        $result = json_decode($this->getContactObj(array('email' => $email))); 

        if (isset($result->code) && $result->code == 0) {
            $response = $result;
        } else {
            $response = $this->addUser(array('email' => $email));
        }

        if(isset($response->data->id)) return $response->data->id;

        throw new \Exception('<pre>'.print_r([
                'name' => 'getCustomerID',
                'email' => $email,
                'result' => $result,
            ], true).'</pre>');
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
    /*** tested ***/
    public function addTagByEmail($email, $tags) {
        $contactID = $this->getCustomerID($email);
        if(is_array($tags)) $tags = implode(',',$tags);

        $response = json_decode($this->addTag($tags, $contactID));

        if(isset($response->code) && $response->code == 0) return $response;

        throw new \Exception('<pre>'.print_r([
                'name' => 'addTagByEmail',
                'email' => $email,
                'tags' => $tags,
                'response' => $response,
            ], true).'</pre>');
    }
    /*** tested ***/
    public function removeTagsByEmail(string $email, array $tags):object {
        $contactID = $this->getCustomerID($email);
        if(is_array($tags)) $tags = implode(',',$tags);

        $requestParams = array(
            "objectID"     => ObjectType::CONTACT, // Object type ID: 0
            "ids"          => $contactID,
            "remove_list"  => $tags
        );
        $response = $this->client->object()->removeTag($requestParams);

        if($response) {
            return json_decode($response);
        }

        throw new \Exception('<pre>'.print_r([
                'name' => 'removeTagsByEmail',
                'email' => $email,
                'tags' => $tags,
                'response' => $response,
                'requestParams' => $requestParams,
            ], true).'</pre>');
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
    }
    /** tested **/
    public function getUserEmail(int $id):string {
        $requestParams = array(
            "id" => $id
        );
        $response = json_decode($this->client->contact()->retrieveSingle($requestParams));

        if(isset($response->data->email)) return $response->data->email;

        throw new \Exception('<pre>'.print_r([
                'name' => 'getUserEmail',
                'id' => $id,
                'response' => $response,
                'requestParams' => $requestParams,
            ], true).'</pre>');
    }
    /** tested **/
    public function deleteUser($email) {
        $requestParams = array(
            "id" => $this->getCustomerID($email)
        );
        $response = json_decode($this->client->contact()->deleteSingle($requestParams));

        if(isset($response->code)) return $response;

        throw new \Exception('<pre>'.print_r([
                'name' => 'deleteUser',
                'email' => $email,
                'response' => $response,
                'requestParams' => $requestParams,
            ], true).'</pre>');
    }

    public function retrieveUser(string $email):object {
        $requestParams = array(
            "id" => $this->getCustomerID($email)
        );
        $response = $this->client->contact()->retrieveSingle($requestParams);

        return json_decode($response);
    }

    public function chargeUser($data) {
        $data = $this->prepareFields($data);

        //--- Charge array
        $requestParams['contact_id'] = $data['contact_id'] ?? $this->getCustomerID($data['email']);
        $requestParams['chargeNow'] = 'chargeNow';
        $requestParams['trans_date'] = time() * 1000;
        $requestParams['invoice_template'] = 1;
        $requestParams['gateway_id'] = $data['payment_gateway'] ?? 6;

        //--- Offer
        //--- Products
        $requestParams['offer']['products'][0]['quantity'] = 1;
        $requestParams['offer']['products'][0]['shipping'] = false;
        $requestParams['offer']['products'][0]['tax'] = false;
        //--- Price
        $requestParams['offer']['products'][0]['price'][0]['price'] = $data['price'];
        $requestParams['offer']['products'][0]['price'][0]['payment_count'] = $data['payment_count']  ?? 1;
        $requestParams['offer']['products'][0]['price'][0]['unit'] = 'month';
        $requestParams['offer']['products'][0]['price'][0]['id'] = $data['product_id'];
        //--- Subscription
        if(isset($data['trial'])) {
            $requestParams['offer']['products'][0]['trial']['price'] = $data['trial']['price'];
            $requestParams['offer']['products'][0]['trial']['payment_count'] = $data['trial']['payment_count'];
            $requestParams['offer']['products'][0]['trial']['unit'] = $data['trial']['unit'];
        }

        $requestParams['offer']['products'][0]['type'] = $this->trasactionType($data['type']);
        $requestParams['offer']['products'][0]['owner'] = 1;
        $requestParams['offer']['products'][0]['offer_to_affiliates'] = false;
        $requestParams['offer']['products'][0]['trial_period_unit'] = 'month';
        $requestParams['offer']['products'][0]['trial_period_count'] = 6;
        $requestParams['offer']['products'][0]['trial_price'] = 0;

        $requestParams['offer']['products'][0]['delay_start'] = 0;
        $requestParams['offer']['products'][0]['subscription_count'] = 999;
        $requestParams['offer']['products'][0]['subscription_unit'] = 'month';
        $requestParams['offer']['products'][0]['taxable'] = false;
        $requestParams['offer']['products'][0]['id'] = $data['product_id'];
//        dd($requestParams);
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
}


/***
 *
 *  $requestParams = array(
"contact_id"       => 40018,
"chargeNow"        => "chargeNow",
"trans_date"       => time()*1000,
"invoice_template" => 1,
"gateway_id"       => 7,
//            "cc_id"            => 1,
"offer"            => array(
"products"          => array(
array(
"quantity"           => 1,
"shipping"           => false,
"tax"                => false,
"price"              => array(
array(
"price"             => 149,
"payment_count"     => 0,
"unit"              => "month",
"id"                => 40
)
),
'trial' => array(
'price' => 0,
'payment_count' => 6,
'unit' => 'month',
),
"type"                => "subscription",
"owner"               => 1,
"offer_to_affiliates" => false,
"trial_period_unit"   => "month",
"trial_period_count"  => 6,
//                        "setup_fee_when"      => "immediately",
//                        "setup_fee_date"      => "string",
"delay_start"         => 0,
"subscription_count"  => 999,
"subscription_unit"   => "month",
"taxable"             => false,
"id"                  => 40
)
)
)
);
 *
 * FROM ONTRAPORT SUPPORT
 *
 * {
"contact_id": "165390",
"chargeNow": "chargeNow",
"trans_date": 1592945788,
"invoice_template": 1,
"gateway_id": 1,
"offer": {
"products": [
{
"quantity": 1,
"total": 149,
"shipping": false,
"tax": false,
"price": [
{
"price": 149,
"payment_count": 1,
"unit": "month",
"id": 40
}
],
"trial": {
"price":0,
"payment_count":6,
"unit":"month"
},
"type": "subscription",
"owner": 1,
"level1": 0,
"level2": 0,
"offer_to_affiliates": false,
"trial_period_unit": "month",
"trial_period_count": 6,
"trial_price": 0,
"setup_fee": 0,
"delay_start": 0,
"subscription_fee": 0,
"subscription_count": 0,
"subscription_unit": "month",
"taxable": true,
"id": 1
}
],
"delay": 0,
"subTotal": 149,
"grandTotal": 149,
"hasTaxes": false,
"hasShipping": false,
"shipping_charge_reoccurring_orders": false,
"send_recurring_invoice": false
}
}

 */
