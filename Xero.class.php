<?php

/*
   Author: Mariusz Stankiewicz (dependent on the work of others - see below)

   Description:
   This is a class I made with help from David Pitman's original PHP Class located here: https://github.com/thinktree/PHP-Xero
   It interacts with the xero.com private application API

   I originally built this so that I can talk to the API in an OO way.

   It is kind of similar to ActiveRecord, but for the Xero API.

   Right now only handles a few method calls, but its all I needed at the time:
    * Create/Find a Contact
    * Find an Account
    * Create a new Invoice
    * Download Invoice as PDF

   License (applies to all classes):
   The MIT License

   Copyright (c) 2007 Andy Smith (Oauth* classes)
   Copyright (c) 2010 David Pitman (Integration with Curl and Oauth)
   Copyright (c) 2012 Mariusz Stankiewicz (Xero class)

   Permission is hereby granted, free of charge, to any person obtaining a copy
   of this software and associated documentation files (the "Software"), to deal
   in the Software without restriction, including without limitation the rights
   to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
   copies of the Software, and to permit persons to whom the Software is
   furnished to do so, subject to the following conditions:

   The above copyright notice and this permission notice shall be included in
   all copies or substantial portions of the Software.

   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
   IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
   FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
   AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
   LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
   OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
   THE SOFTWARE.

   ---

   Want EXAMPLES? https://github.com/discobean/PHP-Xero
*/

class Xero
{
	const ENDPOINT = 'https://api.xero.com/api.xro/2.0/';

	public $key;
	public $secret;
	public $public_cert;
	public $private_key;

	public $consumer; // OAuth
	public $token; // OAuth
	public $signature_method; // OAuth

	public function __construct()
	{
		$this->key = XERO_CONSUMER_KEY;
		$this->secret = XERO_CONSUMER_SECRET;
		$this->public_cert = XERO_CERTIFICATE;
		$this->private_key = XERO_PRIVATE_KEY;

		if(!file_exists($this->public_cert))
			throw new XeroException('Public cert does not exist: ' . $this->public_cert);
		if(!file_exists($this->private_key))
			throw new XeroException('Private key does not exist: ' . $this->private_key);

		$this->consumer = new OAuthConsumer($this->key, $this->secret);
		$this->token = new OAuthToken($this->key, $this->secret);
		$this->signature_method  = new OAuthSignatureMethod_Xero($this->public_cert, $this->private_key);
	}

	// if in is a string, it will be quoted with "", if boolean will be true falst etc..
	private function quoteVar($in)
	{
		return sprintf('"%s"', $in);
	}

	public function post()
	{
		$post_body = $this->toXML();

		$unencoded_url = self::ENDPOINT . $this->__method; // for debugging
		$xero_url = self::ENDPOINT . $this->__method;

		$req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, 'POST', $xero_url, array('xml'=> $post_body));
		$req->sign_request($this->signature_method , $this->consumer, $this->token);

		$ch = curl_init();
		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_URL, $xero_url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $req->to_postdata() );
		curl_setopt($ch, CURLOPT_HEADER, $req->to_header());

		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xero_response = curl_exec($ch);

		if(XeroApiException::isException($xero_response))
			throw new XeroApiException($xero_response);

		$class = get_class($this);
		$res = new $class();
		$res->fromXML($xero_response);

		return $res;
	}

	public function put()
	{
		$post_body = $this->toXML();

		$unencoded_url = self::ENDPOINT . $this->__method; // for debugging
		$xero_url = self::ENDPOINT . $this->__method;

		$req = OAuthRequest::from_consumer_and_token($this->consumer, $this->token, 'PUT', $xero_url);
		$req->sign_request($this->signature_method , $this->consumer, $this->token);

		$fh = fopen('php://memory', 'w+');
		fwrite($fh, $post_body);
		rewind($fh);

		$ch = curl_init($req->to_url());
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_PUT, true);
		curl_setopt($ch, CURLOPT_INFILE, $fh);
		curl_setopt($ch, CURLOPT_INFILESIZE, strlen($post_body));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xero_response = curl_exec($ch);

		fclose($fh);

		if(XeroApiException::isException($xero_response))
			throw new XeroApiException($xero_response);

		$class = get_class($this);
		$res = new $class();
		$res->fromXML($xero_response);

		return $res;
	}

	public function get($query=null)
	{
		$args = func_get_args();

		// call $this->getRaw() to fetch the raw data, using the arguments parsed
		$xero_response = call_user_func_array(array($this, 'getRaw'), $args);

		if(XeroApiException::isException($xero_response))
			throw new XeroApiException($xero_response);

		$this->fromXML($xero_response);
	}

	public function getRaw($query=null)
	{
		$args = func_get_args();
		$query = array_shift($args);

		// this is not ideal, because if a ? is replaced with a string that has a ?, it would fail
		// ideally explode on ?, build the query up again.. This will do for now
		while(count($args) > 0)
		{
			$toQuoteVar = array_shift($args);
			$var = $this->quoteVar($toQuoteVar);
			$query = preg_replace('/\?/', $var, $query, 1);
		}

		$unencoded_url = self::ENDPOINT . $this->__method; // for debugging
		$xero_url = self::ENDPOINT . $this->__method;

		if($query)
		{
			$xero_url .= sprintf('?where=%s', rawurlencode($query));
			$unencoded_url .= sprintf('?where=%s', $query); // for debugging
		}

		$req = OAuthRequest::from_consumer_and_token( $this->consumer, $this->token, 'GET', $xero_url);
		$req->sign_request($this->signature_method , $this->consumer, $this->token);

		$ch = curl_init();

		if($this->HTTP_Accept)
			curl_setopt($ch, CURLOPT_HEADER, "Accept: $this->HTTP_Accept");

		curl_setopt($ch,CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_URL, $req->to_url());
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xero_response = curl_exec($ch);

		return $xero_response;
	}

	// Returns a string representation the first xpath match
	public static function xpath($xml, $xpath)
	{
		list($match) = $xml->xpath($xpath);

		return (string)$match;
	}
}

class XeroException extends Exception { }

class XeroApiException extends XeroException {
	private $xml;

	public function __construct($xml_exception)
	{
		$this->xml = $xml_exception;
		$xml = new SimpleXMLElement($xml_exception);

		list($message) = $xml->xpath('/ApiException/Message');
		list($errorNumber) = $xml->xpath('/ApiException/ErrorNumber');
		list($type) = $xml->xpath('/ApiException/Type');

		parent::__construct((string)$type . ': ' . (string)$message, (int)$errorNumber);

		$this->type = (string)$type;
	}

	public function getXML()
	{
		return $this->xml;
	}

	public static function isException($xml)
	{
		return preg_match('/^<ApiException.*>/', $xml);
	}
}

class XeroCollection extends Xero implements SeekableIterator
{
   protected $__collection = array();

   public function add($object, $key=null)
   {
      if(is_null($key))
         $this->__collection[] = $object;
      else
         $this->__collection[$key] = $object;

      if($key)
         return $key;
      else
         return count($this->__collection) - 1;
   }

	public function first()
	{
		$this->rewind();
		return $this->current();
	}

   public function seek($index)
   {
      return $this->__collection[$index];
   }

   public function rewind()
   {
      reset($this->__collection);
   }

   public function current()
   {
      return current($this->__collection);
   }

   public function key()
   {
      return key($this->__collection);
   }

   public function next()
   {
      return next($this->__collection);
   }

   public function valid()
   {
      return ($this->current() !== false);
   }

   public function count()
   {
      return count($this->__collection);
   }

   public function each()
   {
      return each($this->__collection);
   }
}

class XeroContacts extends XeroCollection {
	public $__method = 'Contacts';

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);

		$results = $xml->xpath('/Response/Contacts/Contact');
		if(count($results) == 0) // if its not wrapped in Response, then check for Invoices root element
			$results = $xml->xpath('/Contacts/Contact');

		foreach($results as $result)
		{
			$contact = new XeroContact();
			$contact->fromXML($result->asXML());

			$this->add($contact);
		}
	}

	public function toXML()
	{
		$xml = new SimpleXMLElement('<Contacts/>');

		foreach($this as $contact)
		{
			$contact->toXML($xml);
		}

		return $xml->asXML();
	}
}

class XeroContact {
	public $ContactID;
	public $ContactStatus;
	public $Name;
	public $FirstName;
	public $LastName;
	public $EmailAddress;
	public $SkypeUserName;
	public $BankAccountDetails;
	public $TaxNumber;
	public $AccountsReceivableTaxType;
	public $AccountsPayableTaxType;
	public $UpdatedDateUTC;
	public $IsSupplier;
	public $IsCustomer;
	public $DefaultCurrency;

	// converts a <Contact> XML to Object
	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);

		$this->ContactID = Xero::xpath($xml, '/Contact/ContactID ');
		$this->ContactStatus = Xero::xpath($xml, '/Contact/ContactStatus ');
		$this->Name = Xero::xpath($xml, '/Contact/Name ');
		$this->FirstName = Xero::xpath($xml, '/Contact/FirstName ');
		$this->LastName = Xero::xpath($xml, '/Contact/LastName ');
		$this->EmailAddress = Xero::xpath($xml, '/Contact/EmailAddress ');
		$this->SkypeUserName = Xero::xpath($xml, '/Contact/SkypeUserName ');
		$this->BankAccountDetails = Xero::xpath($xml, '/Contact/BankAccountDetails ');
		$this->TaxNumber = Xero::xpath($xml, '/Contact/TaxNumber ');
		$this->AccountsReceivableTaxType = Xero::xpath($xml, '/Contact/AccountsReceivableTaxType ');
		$this->AccountsPayableTaxType = Xero::xpath($xml, '/Contact/AccountsPayableTaxType ');
		$this->UpdatedDateUTC = Xero::xpath($xml, '/Contact/UpdatedDateUTC ');
		$this->IsSupplier = Xero::xpath($xml, '/Contact/IsSupplier ');
		$this->IsCustomer = Xero::xpath($xml, '/Contact/IsCustomer ');
		$this->DefaultCurrency = Xero::xpath($xml, '/Contact/DefaultCurrency ');
	}

	public function toXML($xml=null)
	{
		if(is_object($xml))
			$xml = $xml->addChild('Contact');
		else
			$xml = new SimpleXMLElement('<Contact/>');

		if($this->TotalDiscount) $xml->{"TotalDiscount"} = $this->TotalDiscount;
		if($this->ContactID) $xml->{ContactID} = $this->ContactID;
		if($this->ContactStatus) $xml->{ContactStatus} = $this->ContactStatus;
		if($this->Name) $xml->{Name} = $this->Name;
		if($this->FirstName) $xml->{FirstName} = $this->FirstName;
		if($this->LastName) $xml->{LastName} = $this->LastName;
		if($this->EmailAddress) $xml->{EmailAddress} = $this->EmailAddress;
		if($this->SkypeUserName) $xml->{SkypeUserName} = $this->SkypeUserName;
		if($this->BankAccountDetails) $xml->{BankAccountDetails} = $this->BankAccountDetails;
		if($this->TaxNumber) $xml->{TaxNumber} = $this->TaxNumber;
		if($this->AccountsReceivableTaxType) $xml->{AccountsReceivableTaxType} = $this->AccountsReceivableTaxType;
		if($this->AccountsPayableTaxType) $xml->{AccountsPayableTaxType} = $this->AccountsPayableTaxType;
		if($this->UpdatedDateUTC) $xml->{UpdatedDateUTC} = $this->UpdatedDateUTC;
		if($this->IsSupplier) $xml->{IsSupplier} = $this->IsSupplier;
		if($this->IsCustomer) $xml->{IsCustomer} = $this->IsCustomer;
		if($this->DefaultCurrency) $xml->{DefaultCurrency} = $this->DefaultCurrency;

		return $xml->asXML();
	}

}

class XeroInvoices extends XeroCollection {
	public $__method = 'Invoices';

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);
		$results = $xml->xpath('/Response/Invoices/Invoice');
		if(count($results) == 0) // if its not wrapped in Response, then check for Invoices root element
			$results = $xml->xpath('/Invoices/Invoice');

		foreach($results as $result)
		{
			$invoice = new XeroInvoice();
			$invoice->fromXML($result->asXML());

			$this->add($invoice);
		}
	}

	public function toXML()
	{
		$xml = new SimpleXMLElement('<Invoices/>');

		foreach($this as $invoice)
		{
			$invoice->toXML($xml);
		}

		return $xml->asXML();
	}
}

class XeroInvoice {
	public $Contact; // XeroContact object
	public $Type;
	public $Date;
	public $DueDate;
	public $LineAmountTypes;
	public $InvoiceNumber;
	public $Reference;
	public $BrandingThemeID;
	public $Url;
	public $CurrencyCode;
	public $Status;
	public $SubTotal;
	public $TotalTax;
	public $Total;
	public $InvoiceID;
	public $Payments;
	public $AmountDue;
	public $AmountPaid;
	public $AmountCredited;
	public $UpdatedDateUTC;
	public $SentToContact;
	public $CreditNotes;
	public $TotalDiscount;

	public $__LineItems;

	public function __construct()
	{
		$this->__LineItems = new XeroInvoiceLineItems();
	}

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);

		list($xml_contact) = $xml->xpath('/Invoice/Contact');
		$this->Contact = new XeroContact();
		$this->Contact->fromXML($xml_contact->asXML());

		$this->Type = Xero::xpath($xml, '/Invoice/Type');
		$this->Date = Xero::xpath($xml, '/Invoice/Date');
		$this->DueDate = Xero::xpath($xml, '/Invoice/DueDate');
		$this->LineAmountTypes = Xero::xpath($xml, '/Invoice/LineAmountTypes');
		$this->InvoiceNumber = Xero::xpath($xml, '/Invoice/InvoiceNumber');
		$this->Reference = Xero::xpath($xml, '/Invoice/Reference');
		$this->BrandingThemeID = Xero::xpath($xml, '/Invoice/BrandingThemeID');
		$this->Url = Xero::xpath($xml, '/Invoice/Url');
		$this->CurrencyCode = Xero::xpath($xml, '/Invoice/CurrencyCode');
		$this->Status = Xero::xpath($xml, '/Invoice/Status');
		$this->SubTotal = Xero::xpath($xml, '/Invoice/SubTotal');
		$this->TotalTax = Xero::xpath($xml, '/Invoice/TotalTax');
		$this->Total = Xero::xpath($xml, '/Invoice/Total');
		$this->InvoiceID = Xero::xpath($xml, '/Invoice/InvoiceID');
		$this->Payments = Xero::xpath($xml, '/Invoice/Payments');
		$this->AmountDue = Xero::xpath($xml, '/Invoice/AmountDue');
		$this->AmountPaid = Xero::xpath($xml, '/Invoice/AmountPaid');
		$this->AmountCredited = Xero::xpath($xml, '/Invoice/AmountCredited');
		$this->UpdatedDateUTC = Xero::xpath($xml, '/Invoice/UpdatedDateUTC');
		$this->SentToContact = Xero::xpath($xml, '/Invoice/SentToContact');
		$this->CreditNotes = Xero::xpath($xml, '/Invoice/CreditNotes');
		$this->TotalDiscount = Xero::xpath($xml, '/Invoice/TotalDiscount');
	}

	public function add(XeroInvoiceLineItem $lineItem)
	{
		$this->__LineItems->add($lineItem);
	}

	public function toXML($xml=null)
	{
		if(is_object($xml))
			$xml = $xml->addChild('Invoice');
		else
			$xml = new SimpleXMLElement('<Invoice/>');

		if(is_object($this->Contact))
			$this->Contact->toXML($xml);

		if($this->Type) $xml->{"Type"} = $this->Type;
		if($this->Date) $xml->{"Date"} = $this->Date;
		if($this->DueDate) $xml->{"DueDate"} = $this->DueDate;
		if($this->LineAmountTypes) $xml->{"LineAmountTypes"} = $this->LineAmountTypes;
		if($this->InvoiceNumber) $xml->{"InvoiceNumber"} = $this->InvoiceNumber;
		if($this->Reference) $xml->{"Reference"} = $this->Reference;
		if($this->BrandingThemeID) $xml->{"BrandingThemeID"} = $this->BrandingThemeID;
		if($this->Url) $xml->{"Url"} = $this->Url;
		if($this->CurrencyCode) $xml->{"CurrencyCode"} = $this->CurrencyCode;
		if($this->Status) $xml->{"Status"} = $this->Status;
		if($this->SubTotal) $xml->{"SubTotal"} = $this->SubTotal;
		if($this->TotalTax) $xml->{"TotalTax"} = $this->TotalTax;
		if($this->Total) $xml->{"Total"} = $this->Total;
		if($this->InvoiceID) $xml->{"InvoiceID"} = $this->InvoiceID;
		if($this->Payments) $xml->{"Payments"} = $this->Payments;
		if($this->AmountDue) $xml->{"AmountDue"} = $this->AmountDue;
		if($this->AmountPaid) $xml->{"AmountPaid"} = $this->AmountPaid;
		if($this->AmountCredited) $xml->{"AmountCredited"} = $this->AmountCredited;
		if($this->UpdatedDateUTC) $xml->{"UpdatedDateUTC"} = $this->UpdatedDateUTC;
		if($this->SentToContact) $xml->{"SentToContact"} = $this->SentToContact;
		if($this->CreditNotes) $xml->{"CreditNotes"} = $this->CreditNotes;
		if($this->TotalDiscount) $xml->{"TotalDiscount"} = $this->TotalDiscount;

		$this->__LineItems->toXML($xml);

		return $xml->asXML();
	}

	public function getPDF($invoiceNumber = null)
	{
		if($invoiceNumber)
			$this->InvoiceNumber = $invoiceNumber;

		$unencoded_url = Xero::ENDPOINT . 'Invoices/' . $this->InvoiceNumber;
		$xero_url = Xero::ENDPOINT . 'Invoices/' . $this->InvoiceNumber;

		$xero = new Xero();
		$req = OAuthRequest::from_consumer_and_token( $xero->consumer, $xero->token, 'GET', $xero_url);
		$req->sign_request($xero->signature_method , $xero->consumer, $xero->token);

		$ch = curl_init();

		curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/pdf"));
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_URL, $req->to_url());
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		$xero_response = curl_exec($ch);

		return $xero_response;
	}


}

class XeroInvoiceLineItems extends XeroCollection {
	public function toXML($xml)
	{
		if($this->count() == 0)
			return;

		if(is_object($xml))
			$xml = $xml->addChild('LineItems');
		else
			$xml = new SimpleXMLElement('<LineItems/>');

		foreach($this as $lineItem)
		{
			$lineItem->toXML($xml);
		}

		return $xml->asXML();
	}
}

class XeroInvoiceLineItem {
	public $Description;
	public $Quantity;
	public $UnitAmount;
	public $ItemCode;
	public $AccountCode;
	public $TaxType;
	public $TaxAmount;
	public $LineAmount;
	public $Tracking;
	public $DiscountRate;

	public function toXML($xml=null)
	{
		if(is_object($xml))
			$xml = $xml->addChild('LineItem');
		else
			$xml = new SimpleXMLElement('<LineItem/>');

		if($this->Description) $xml->{"Description"} = $this->Description;
		if($this->Quantity) $xml->{"Quantity"} = $this->Quantity;
		if($this->UnitAmount) $xml->{"UnitAmount"} = $this->UnitAmount;
		if($this->ItemCode) $xml->{"ItemCode"} = $this->ItemCode;
		if($this->AccountCode) $xml->{"AccountCode"} = $this->AccountCode;
		if($this->TaxType) $xml->{"TaxType"} = $this->TaxType;
		if($this->TaxAmount) $xml->{"TaxAmount"} = $this->TaxAmount;
		if($this->LineAmount) $xml->{"LineAmount"} = $this->LineAmount;
		if($this->Tracking) $xml->{"Tracking"} = $this->Tracking;
		if($this->DiscountRate) $xml->{"DiscountRate"} = $this->DiscountRate;

		return $xml->asXML();
	}
}

class XeroPayments extends XeroCollection {
	public $__method = 'Payments';

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);
		$results = $xml->xpath('/Response/Payments/Payment');
		if(count($results) == 0) // if its not wrapped in Response, then check for Payments root element
			$results = $xml->xpath('/Payments/Payment');

		foreach($results as $result)
		{
			$payment = new XeroPayment();
			$payment->fromXML($result->asXML());

			$this->add($payment);
		}
	}

	public function toXML()
	{
		$xml = new SimpleXMLElement('<Payments/>');

		foreach($this as $payment)
		{
			$payment->toXML($xml);
		}

		return $xml->asXML();
	}
}

class XeroPayment {
	public $Invoice; // XeroInvoice object
	public $Account; // XeroAccount object
	public $PaymentID;
	public $Date;
	public $CurrencyRate;
	public $Amount;
	public $Reference;

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);

		list($xml_invoice) = $xml->xpath('/Payment/Invoice');
		$this->Invoice = new XeroInvoice();
		$this->Invoice->fromXML($xml_invoice->asXML());

		list($xml_account) = $xml->xpath('/Payment/Account');
		$this->Account = new XeroAccount();
		$this->Account->fromXML($xml_account->asXML());

		$this->PaymentID = Xero::xpath($xml, '/Payment/PaymentID');
		$this->Date = Xero::xpath($xml, '/Payment/Date');
		$this->CurrencyRate = Xero::xpath($xml, '/Payment/CurrencyRate');
		$this->Amount = Xero::xpath($xml, '/Payment/Amount');
		$this->Reference = Xero::xpath($xml, '/Payment/Reference');
	}

	public function toXML($xml=null)
	{
		if(is_object($xml))
			$xml = $xml->addChild('Payment');
		else
			$xml = new SimpleXMLElement('<Payment/>');

		if(is_object($this->Invoice))
			$this->Invoice->toXML($xml);

		if(is_object($this->Account))
			$this->Account->toXML($xml);

		if($this->PaymentID) $xml->{PaymentID} = $this->PaymentID;
		if($this->Date) $xml->{Date} = $this->Date;
		if($this->CurrencyRate) $xml->{CurrencyRate} = $this->CurrencyRate;
		if($this->Amount) $xml->{Amount} = $this->Amount;
		if($this->Reference) $xml->{Reference} = $this->Reference;

		return $xml->asXML();
	}
}

class XeroAccounts extends XeroCollection {
	public $__method = 'Accounts';

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);
		$results = $xml->xpath('/Response/Accounts/Account');
		if(count($results) == 0) // if its not wrapped in Response, then check for Accounts root element
			$results = $xml->xpath('/Accounts/Account');

		foreach($results as $result)
		{
			$account = new XeroAccount();
			$account->fromXML($result->asXML());

			$this->add($account);
		}
	}

	public function toXML()
	{
		$xml = new SimpleXMLElement('<Accounts/>');

		foreach($this as $account)
		{
			$account->toXML($xml);
		}

		return $xml->asXML();
	}
}

class XeroAccount {
	public $AccountID;
	public $Code;
	public $Name;
	public $Status;
	public $Type;
	public $Tax;
	public $Description;
	public $Class;
	public $SystemAccount;
	public $EnablePaymentsToAccount;
	public $ShowInExpenseClaims;
	public $BankAccountNumber;
	public $CurrencyCode;
	public $ReportingCode;
	public $ReportingCodeName;

	public function fromXML($xml)
	{
		$xml = new SimpleXMLElement($xml);

		$this->AccountID = Xero::xpath($xml, '/Account/AccountID');
		$this->Code = Xero::xpath($xml, '/Account/Code');
		$this->Name = Xero::xpath($xml, '/Account/Name');
		$this->Status = Xero::xpath($xml, '/Account/Status');
		$this->Type = Xero::xpath($xml, '/Account/Type');
		$this->Tax = Xero::xpath($xml, '/Account/Tax');
		$this->Description = Xero::xpath($xml, '/Account/Description');
		$this->Class = Xero::xpath($xml, '/Account/Class');
		$this->SystemAccount = Xero::xpath($xml, '/Account/SystemAccount');
		$this->EnablePaymentsToAccount = Xero::xpath($xml, '/Account/EnablePaymentsToAccount');
		$this->ShowInExpenseClaims = Xero::xpath($xml, '/Account/ShowInExpenseClaims');
		$this->BankAccountNumber = Xero::xpath($xml, '/Account/BankAccountNumber');
		$this->CurrencyCode = Xero::xpath($xml, '/Account/CurrencyCode');
		$this->ReportingCode = Xero::xpath($xml, '/Account/ReportingCode');
		$this->ReportingCodeName = Xero::xpath($xml, '/Account/ReportingCodeName');
	}

	public function toXML($xml=null)
	{
		if(is_object($xml))
			$xml = $xml->addChild('Account');
		else
			$xml = new SimpleXMLElement('<Account/>');

		if($this->AccountID) $xml->{AccountID} = $this->AccountID;
		if($this->Code) $xml->{Code} = $this->Code;
		if($this->Name) $xml->{Name} = $this->Name;
		if($this->Status) $xml->{Status} = $this->Status;
		if($this->Type) $xml->{Type} = $this->Type;
		if($this->Tax) $xml->{Tax} = $this->Tax;
		if($this->Description) $xml->{Description} = $this->Description;
		if($this->Class) $xml->{'Class'} = $this->Class;
		if($this->SystemAccount) $xml->{SystemAccount} = $this->SystemAccount;
		if($this->EnablePaymentsToAccount) $xml->{EnablePaymentsToAccount} = $this->EnablePaymentsToAccount;
		if($this->ShowInExpenseClaims) $xml->{ShowInExpenseClaims} = $this->ShowInExpenseClaims;
		if($this->BankAccountNumber) $xml->{BankAccountNumber} = $this->BankAccountNumber;
		if($this->CurrencyCode) $xml->{CurrencyCode} = $this->CurrencyCode;
		if($this->ReportingCode) $xml->{ReportingCode} = $this->ReportingCode;
		if($this->ReportingCodeName) $xml->{ReportingCodeName} = $this->ReportingCodeName;

		return $xml->asXML();
	}
}



class OAuthException extends Exception {
  // pass
}

class OAuthConsumer {
  public $key;
  public $secret;

  function __construct($key, $secret, $callback_url=NULL) {
    $this->key = $key;
    $this->secret = $secret;
    $this->callback_url = $callback_url;
  }

  function __toString() {
    return "OAuthConsumer[key=$this->key,secret=$this->secret]";
  }
}

class OAuthToken {
  // access tokens and request tokens
  public $key;
  public $secret;

  /**
   * key = the token
   * secret = the token secret
   */
  function __construct($key, $secret) {
    $this->key = $key;
    $this->secret = $secret;
  }

  /**
   * generates the basic string serialization of a token that a server
   * would respond to request_token and access_token calls with
   */
  function to_string() {
    return "oauth_token=" .
           OAuthUtil::urlencode_rfc3986($this->key) .
           "&oauth_token_secret=" .
           OAuthUtil::urlencode_rfc3986($this->secret);
  }

  function __toString() {
    return $this->to_string();
  }
}

/**
 * A class for implementing a Signature Method
 * See section 9 ("Signing Requests") in the spec
 */
abstract class OAuthSignatureMethod {
  /**
   * Needs to return the name of the Signature Method (ie HMAC-SHA1)
   * @return string
   */
  abstract public function get_name();

  /**
   * Build up the signature
   * NOTE: The output of this function MUST NOT be urlencoded.
   * the encoding is handled in OAuthRequest when the final
   * request is serialized
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @return string
   */
  abstract public function build_signature($request, $consumer, $token);

  /**
   * Verifies that a given signature is correct
   * @param OAuthRequest $request
   * @param OAuthConsumer $consumer
   * @param OAuthToken $token
   * @param string $signature
   * @return bool
   */
  public function check_signature($request, $consumer, $token, $signature) {
    $built = $this->build_signature($request, $consumer, $token);
    return $built == $signature;
  }
}

/**
 * The HMAC-SHA1 signature method uses the HMAC-SHA1 signature algorithm as defined in [RFC2104]
 * where the Signature Base String is the text and the key is the concatenated values (each first
 * encoded per Parameter Encoding) of the Consumer Secret and Token Secret, separated by an '&'
 * character (ASCII code 38) even if empty.
 *   - Chapter 9.2 ("HMAC-SHA1")
 */
class OAuthSignatureMethod_HMAC_SHA1 extends OAuthSignatureMethod {
  function get_name() {
    return "HMAC-SHA1";
  }

  public function build_signature($request, $consumer, $token) {
    $base_string = $request->get_signature_base_string();
    $request->base_string = $base_string;

    $key_parts = array(
      $consumer->secret,
      ($token) ? $token->secret : ""
    );

    $key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
    $key = implode('&', $key_parts);

    return base64_encode(hash_hmac('sha1', $base_string, $key, true));
  }
}

/**
 * The PLAINTEXT method does not provide any security protection and SHOULD only be used
 * over a secure channel such as HTTPS. It does not use the Signature Base String.
 *   - Chapter 9.4 ("PLAINTEXT")
 */
class OAuthSignatureMethod_PLAINTEXT extends OAuthSignatureMethod {
  public function get_name() {
    return "PLAINTEXT";
  }

  /**
   * oauth_signature is set to the concatenated encoded values of the Consumer Secret and
   * Token Secret, separated by a '&' character (ASCII code 38), even if either secret is
   * empty. The result MUST be encoded again.
   *   - Chapter 9.4.1 ("Generating Signatures")
   *
   * Please note that the second encoding MUST NOT happen in the SignatureMethod, as
   * OAuthRequest handles this!
   */
  public function build_signature($request, $consumer, $token) {
    $key_parts = array(
      $consumer->secret,
      ($token) ? $token->secret : ""
    );

    $key_parts = OAuthUtil::urlencode_rfc3986($key_parts);
    $key = implode('&', $key_parts);
    $request->base_string = $key;

    return $key;
  }
}

/**
 * The RSA-SHA1 signature method uses the RSASSA-PKCS1-v1_5 signature algorithm as defined in
 * [RFC3447] section 8.2 (more simply known as PKCS#1), using SHA-1 as the hash function for
 * EMSA-PKCS1-v1_5. It is assumed that the Consumer has provided its RSA public key in a
 * verified way to the Service Provider, in a manner which is beyond the scope of this
 * specification.
 *   - Chapter 9.3 ("RSA-SHA1")
 */
abstract class OAuthSignatureMethod_RSA_SHA1 extends OAuthSignatureMethod {
  public function get_name() {
    return "RSA-SHA1";
  }

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  // (2) fetch via http using a url provided by the requester
  // (3) some sort of specific discovery code based on request
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_public_cert(&$request);

  // Up to the SP to implement this lookup of keys. Possible ideas are:
  // (1) do a lookup in a table of trusted certs keyed off of consumer
  //
  // Either way should return a string representation of the certificate
  protected abstract function fetch_private_cert(&$request);

  public function build_signature($request, $consumer, $token) {
    $base_string = $request->get_signature_base_string();
    $request->base_string = $base_string;

    // Fetch the private key cert based on the request
    $cert = $this->fetch_private_cert($request);

    // Pull the private key ID from the certificate
    $privatekeyid = openssl_get_privatekey($cert);

    // Sign using the key
    $ok = openssl_sign($base_string, $signature, $privatekeyid);

    // Release the key resource
    openssl_free_key($privatekeyid);

    return base64_encode($signature);
  }

  public function check_signature($request, $consumer, $token, $signature) {
    $decoded_sig = base64_decode($signature);

    $base_string = $request->get_signature_base_string();

    // Fetch the public key cert based on the request
    $cert = $this->fetch_public_cert($request);

    // Pull the public key ID from the certificate
    $publickeyid = openssl_get_publickey($cert);

    // Check the computed signature against the one passed in the query
    $ok = openssl_verify($base_string, $decoded_sig, $publickeyid);

    // Release the key resource
    openssl_free_key($publickeyid);

    return $ok == 1;
  }
}

class OAuthRequest {
  private $parameters;
  private $http_method;
  private $http_url;
  // for debug purposes
  public $base_string;
  public static $version = '1.0';
  public static $POST_INPUT = 'php://input';

  function __construct($http_method, $http_url, $parameters=NULL) {
    @$parameters or $parameters = array();
    $parameters = array_merge( OAuthUtil::parse_parameters(parse_url($http_url, PHP_URL_QUERY)), $parameters);
    $this->parameters = $parameters;
    $this->http_method = $http_method;
    $this->http_url = $http_url;
  }


  /**
   * attempt to build up a request from what was passed to the server
   */
  public static function from_request($http_method=NULL, $http_url=NULL, $parameters=NULL) {
    $scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on")
              ? 'http'
              : 'https';
    @$http_url or $http_url = $scheme .
                              '://' . $_SERVER['HTTP_HOST'] .
                              ':' .
                              $_SERVER['SERVER_PORT'] .
                              $_SERVER['REQUEST_URI'];
    @$http_method or $http_method = $_SERVER['REQUEST_METHOD'];

    // We weren't handed any parameters, so let's find the ones relevant to
    // this request.
    // If you run XML-RPC or similar you should use this to provide your own
    // parsed parameter-list
    if (!$parameters) {
      // Find request headers
      $request_headers = OAuthUtil::get_headers();

      // Parse the query-string to find GET parameters
      $parameters = OAuthUtil::parse_parameters($_SERVER['QUERY_STRING']);

      // It's a POST request of the proper content-type, so parse POST
      // parameters and add those overriding any duplicates from GET
      if ($http_method == "POST"
          && @strstr($request_headers["Content-Type"],
                     "application/x-www-form-urlencoded")
          ) {
        $post_data = OAuthUtil::parse_parameters(
          file_get_contents(self::$POST_INPUT)
        );
        $parameters = array_merge($parameters, $post_data);
      }

      // We have a Authorization-header with OAuth data. Parse the header
      // and add those overriding any duplicates from GET or POST
      if (@substr($request_headers['Authorization'], 0, 6) == "OAuth ") {
        $header_parameters = OAuthUtil::split_header(
          $request_headers['Authorization']
        );
        $parameters = array_merge($parameters, $header_parameters);
      }

    }

    return new OAuthRequest($http_method, $http_url, $parameters);
  }

  /**
   * pretty much a helper function to set up the request
   */
  public static function from_consumer_and_token($consumer, $token, $http_method, $http_url, $parameters=NULL) {
    @$parameters or $parameters = array();
    $defaults = array("oauth_version" => OAuthRequest::$version,
                      "oauth_nonce" => OAuthRequest::generate_nonce(),
                      "oauth_timestamp" => OAuthRequest::generate_timestamp(),
                      "oauth_consumer_key" => $consumer->key);
    if ($token)
      $defaults['oauth_token'] = $token->key;

    $parameters = array_merge($defaults, $parameters);

    return new OAuthRequest($http_method, $http_url, $parameters);
  }

  public function set_parameter($name, $value, $allow_duplicates = true) {
    if ($allow_duplicates && isset($this->parameters[$name])) {
      // We have already added parameter(s) with this name, so add to the list
      if (is_scalar($this->parameters[$name])) {
        // This is the first duplicate, so transform scalar (string)
        // into an array so we can add the duplicates
        $this->parameters[$name] = array($this->parameters[$name]);
      }

      $this->parameters[$name][] = $value;
    } else {
      $this->parameters[$name] = $value;
    }
  }

  public function get_parameter($name) {
    return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
  }

  public function get_parameters() {
    return $this->parameters;
  }

  public function unset_parameter($name) {
    unset($this->parameters[$name]);
  }

  /**
   * The request parameters, sorted and concatenated into a normalized string.
   * @return string
   */
  public function get_signable_parameters() {
    // Grab all parameters
    $params = $this->parameters;

    // Remove oauth_signature if present
    // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
    if (isset($params['oauth_signature'])) {
      unset($params['oauth_signature']);
    }

    return OAuthUtil::build_http_query($params);
  }

  /**
   * Returns the base string of this request
   *
   * The base string defined as the method, the url
   * and the parameters (normalized), each urlencoded
   * and the concated with &.
   */
  public function get_signature_base_string() {
    $parts = array(
      $this->get_normalized_http_method(),
      $this->get_normalized_http_url(),
      $this->get_signable_parameters()
    );

    $parts = OAuthUtil::urlencode_rfc3986($parts);

    return implode('&', $parts);
  }

  /**
   * just uppercases the http method
   */
  public function get_normalized_http_method() {
    return strtoupper($this->http_method);
  }

  /**
   * parses the url and rebuilds it to be
   * scheme://host/path
   */
  public function get_normalized_http_url() {
    $parts = parse_url($this->http_url);

    $port = @$parts['port'];
    $scheme = $parts['scheme'];
    $host = $parts['host'];
    $path = @$parts['path'];

    $port or $port = ($scheme == 'https') ? '443' : '80';

    if (($scheme == 'https' && $port != '443')
        || ($scheme == 'http' && $port != '80')) {
      $host = "$host:$port";
    }
    return "$scheme://$host$path";
  }

  /**
   * builds a url usable for a GET request
   */
  public function to_url() {
    $post_data = $this->to_postdata();
    $out = $this->get_normalized_http_url();
    if ($post_data) {
      $out .= '?'.$post_data;
    }
    return $out;
  }

  /**
   * builds the data one would send in a POST request
   */
  public function to_postdata() {
    return OAuthUtil::build_http_query($this->parameters);
  }

  /**
   * builds the Authorization: header
   */
  public function to_header($realm=null) {
    $first = true;
	if($realm) {
      $out = 'Authorization: OAuth realm="' . OAuthUtil::urlencode_rfc3986($realm) . '"';
      $first = false;
    } else
      $out = 'Authorization: OAuth';

    $total = array();
    foreach ($this->parameters as $k => $v) {
      if (substr($k, 0, 5) != "oauth") continue;
      if (is_array($v)) {
        throw new OAuthException('Arrays not supported in headers');
      }
      $out .= ($first) ? ' ' : ',';
      $out .= OAuthUtil::urlencode_rfc3986($k) .
              '="' .
              OAuthUtil::urlencode_rfc3986($v) .
              '"';
      $first = false;
    }
    return $out;
  }

  public function __toString() {
    return $this->to_url();
  }


  public function sign_request($signature_method, $consumer, $token) {
    $this->set_parameter(
      "oauth_signature_method",
      $signature_method->get_name(),
      false
    );
    $signature = $this->build_signature($signature_method, $consumer, $token);
    $this->set_parameter("oauth_signature", $signature, false);
  }

  public function build_signature($signature_method, $consumer, $token) {
    $signature = $signature_method->build_signature($this, $consumer, $token);
    return $signature;
  }

  /**
   * util function: current timestamp
   */
  private static function generate_timestamp() {
    return time();
  }

  /**
   * util function: current nonce
   */
  private static function generate_nonce() {
    $mt = microtime();
    $rand = mt_rand();

    return md5($mt . $rand); // md5s look nicer than numbers
  }
}

class OAuthServer {
  protected $timestamp_threshold = 300; // in seconds, five minutes
  protected $version = '1.0';             // hi blaine
  protected $signature_methods = array();

  protected $data_store;

  function __construct($data_store) {
    $this->data_store = $data_store;
  }

  public function add_signature_method($signature_method) {
    $this->signature_methods[$signature_method->get_name()] =
      $signature_method;
  }

  // high level functions

  /**
   * process a request_token request
   * returns the request token on success
   */
  public function fetch_request_token(&$request) {
    $this->get_version($request);

    $consumer = $this->get_consumer($request);

    // no token required for the initial token request
    $token = NULL;

    $this->check_signature($request, $consumer, $token);

    // Rev A change
    $callback = $request->get_parameter('oauth_callback');
    $new_token = $this->data_store->new_request_token($consumer, $callback);

    return $new_token;
  }

  /**
   * process an access_token request
   * returns the access token on success
   */
  public function fetch_access_token(&$request) {
    $this->get_version($request);

    $consumer = $this->get_consumer($request);

    // requires authorized request token
    $token = $this->get_token($request, $consumer, "request");

    $this->check_signature($request, $consumer, $token);

    // Rev A change
    $verifier = $request->get_parameter('oauth_verifier');
    $new_token = $this->data_store->new_access_token($token, $consumer, $verifier);

    return $new_token;
  }

  /**
   * verify an api call, checks all the parameters
   */
  public function verify_request(&$request) {
    $this->get_version($request);
    $consumer = $this->get_consumer($request);
    $token = $this->get_token($request, $consumer, "access");
    $this->check_signature($request, $consumer, $token);
    return array($consumer, $token);
  }

  // Internals from here
  /**
   * version 1
   */
  private function get_version(&$request) {
    $version = $request->get_parameter("oauth_version");
    if (!$version) {
      // Service Providers MUST assume the protocol version to be 1.0 if this parameter is not present.
      // Chapter 7.0 ("Accessing Protected Ressources")
      $version = '1.0';
    }
    if ($version !== $this->version) {
      throw new OAuthException("OAuth version '$version' not supported");
    }
    return $version;
  }

  /**
   * figure out the signature with some defaults
   */
  private function get_signature_method(&$request) {
    $signature_method =
        @$request->get_parameter("oauth_signature_method");

    if (!$signature_method) {
      // According to chapter 7 ("Accessing Protected Ressources") the signature-method
      // parameter is required, and we can't just fallback to PLAINTEXT
      throw new OAuthException('No signature method parameter. This parameter is required');
    }

    if (!in_array($signature_method,
                  array_keys($this->signature_methods))) {
      throw new OAuthException(
        "Signature method '$signature_method' not supported " .
        "try one of the following: " .
        implode(", ", array_keys($this->signature_methods))
      );
    }
    return $this->signature_methods[$signature_method];
  }

  /**
   * try to find the consumer for the provided request's consumer key
   */
  private function get_consumer(&$request) {
    $consumer_key = @$request->get_parameter("oauth_consumer_key");
    if (!$consumer_key) {
      throw new OAuthException("Invalid consumer key");
    }

    $consumer = $this->data_store->lookup_consumer($consumer_key);
    if (!$consumer) {
      throw new OAuthException("Invalid consumer");
    }

    return $consumer;
  }

  /**
   * try to find the token for the provided request's token key
   */
  private function get_token(&$request, $consumer, $token_type="access") {
    $token_field = @$request->get_parameter('oauth_token');
    $token = $this->data_store->lookup_token(
      $consumer, $token_type, $token_field
    );
    if (!$token) {
      throw new OAuthException("Invalid $token_type token: $token_field");
    }
    return $token;
  }

  /**
   * all-in-one function to check the signature on a request
   * should guess the signature method appropriately
   */
  private function check_signature(&$request, $consumer, $token) {
    // this should probably be in a different method
    $timestamp = @$request->get_parameter('oauth_timestamp');
    $nonce = @$request->get_parameter('oauth_nonce');

    $this->check_timestamp($timestamp);
    $this->check_nonce($consumer, $token, $nonce, $timestamp);

    $signature_method = $this->get_signature_method($request);

    $signature = $request->get_parameter('oauth_signature');
    $valid_sig = $signature_method->check_signature(
      $request,
      $consumer,
      $token,
      $signature
    );

    if (!$valid_sig) {
      throw new OAuthException("Invalid signature");
    }
  }

  /**
   * check that the timestamp is new enough
   */
  private function check_timestamp($timestamp) {
    if( ! $timestamp )
      throw new OAuthException(
        'Missing timestamp parameter. The parameter is required'
      );

    // verify that timestamp is recentish
    $now = time();
    if (abs($now - $timestamp) > $this->timestamp_threshold) {
      throw new OAuthException(
        "Expired timestamp, yours $timestamp, ours $now"
      );
    }
  }

  /**
   * check that the nonce is not repeated
   */
  private function check_nonce($consumer, $token, $nonce, $timestamp) {
    if( ! $nonce )
      throw new OAuthException(
        'Missing nonce parameter. The parameter is required'
      );

    // verify that the nonce is uniqueish
    $found = $this->data_store->lookup_nonce(
      $consumer,
      $token,
      $nonce,
      $timestamp
    );
    if ($found) {
      throw new OAuthException("Nonce already used: $nonce");
    }
  }

}

class OAuthDataStore {
  function lookup_consumer($consumer_key) {
    // implement me
  }

  function lookup_token($consumer, $token_type, $token) {
    // implement me
  }

  function lookup_nonce($consumer, $token, $nonce, $timestamp) {
    // implement me
  }

  function new_request_token($consumer, $callback = null) {
    // return a new token attached to this consumer
  }

  function new_access_token($token, $consumer, $verifier = null) {
    // return a new access token attached to this consumer
    // for the user associated with this token if the request token
    // is authorized
    // should also invalidate the request token
  }

}

class OAuthUtil {
  public static function urlencode_rfc3986($input) {
  if (is_array($input)) {
    return array_map(array('OAuthUtil', 'urlencode_rfc3986'), $input);
  } else if (is_scalar($input)) {
    return str_replace(
      '+',
      ' ',
      str_replace('%7E', '~', rawurlencode($input))
    );
  } else {
    return '';
  }
}


  // This decode function isn't taking into consideration the above
  // modifications to the encoding process. However, this method doesn't
  // seem to be used anywhere so leaving it as is.
  public static function urldecode_rfc3986($string) {
    return urldecode($string);
  }

  // Utility function for turning the Authorization: header into
  // parameters, has to do some unescaping
  // Can filter out any non-oauth parameters if needed (default behaviour)
  public static function split_header($header, $only_allow_oauth_parameters = true) {
    $pattern = '/(([-_a-z]*)=("([^"]*)"|([^,]*)),?)/';
    $offset = 0;
    $params = array();
    while (preg_match($pattern, $header, $matches, PREG_OFFSET_CAPTURE, $offset) > 0) {
      $match = $matches[0];
      $header_name = $matches[2][0];
      $header_content = (isset($matches[5])) ? $matches[5][0] : $matches[4][0];
      if (preg_match('/^oauth_/', $header_name) || !$only_allow_oauth_parameters) {
        $params[$header_name] = OAuthUtil::urldecode_rfc3986($header_content);
      }
      $offset = $match[1] + strlen($match[0]);
    }

    if (isset($params['realm'])) {
      unset($params['realm']);
    }

    return $params;
  }

  // helper to try to sort out headers for people who aren't running apache
  public static function get_headers() {
    if (function_exists('apache_request_headers')) {
      // we need this to get the actual Authorization: header
      // because apache tends to tell us it doesn't exist
      $headers = apache_request_headers();

      // sanitize the output of apache_request_headers because
      // we always want the keys to be Cased-Like-This and arh()
      // returns the headers in the same case as they are in the
      // request
      $out = array();
      foreach( $headers AS $key => $value ) {
        $key = str_replace(
            " ",
            "-",
            ucwords(strtolower(str_replace("-", " ", $key)))
          );
        $out[$key] = $value;
      }
    } else {
      // otherwise we don't have apache and are just going to have to hope
      // that $_SERVER actually contains what we need
      $out = array();
      if( isset($_SERVER['CONTENT_TYPE']) )
        $out['Content-Type'] = $_SERVER['CONTENT_TYPE'];
      if( isset($_ENV['CONTENT_TYPE']) )
        $out['Content-Type'] = $_ENV['CONTENT_TYPE'];

      foreach ($_SERVER as $key => $value) {
        if (substr($key, 0, 5) == "HTTP_") {
          // this is chaos, basically it is just there to capitalize the first
          // letter of every word that is not an initial HTTP and strip HTTP
          // code from przemek
          $key = str_replace(
            " ",
            "-",
            ucwords(strtolower(str_replace("_", " ", substr($key, 5))))
          );
          $out[$key] = $value;
        }
      }
    }
    return $out;
  }

  // This function takes a input like a=b&a=c&d=e and returns the parsed
  // parameters like this
  // array('a' => array('b','c'), 'd' => 'e')
  public static function parse_parameters( $input ) {
    if (!isset($input) || !$input) return array();

    $pairs = explode('&', $input);

    $parsed_parameters = array();
    foreach ($pairs as $pair) {
      $split = explode('=', $pair, 2);
      $parameter = OAuthUtil::urldecode_rfc3986($split[0]);
      $value = isset($split[1]) ? OAuthUtil::urldecode_rfc3986($split[1]) : '';

      if (isset($parsed_parameters[$parameter])) {
        // We have already recieved parameter(s) with this name, so add to the list
        // of parameters with this name

        if (is_scalar($parsed_parameters[$parameter])) {
          // This is the first duplicate, so transform scalar (string) into an array
          // so we can add the duplicates
          $parsed_parameters[$parameter] = array($parsed_parameters[$parameter]);
        }

        $parsed_parameters[$parameter][] = $value;
      } else {
        $parsed_parameters[$parameter] = $value;
      }
    }
    return $parsed_parameters;
  }

  public static function build_http_query($params) {
    if (!$params) return '';

    // Urlencode both keys and values
    $keys = OAuthUtil::urlencode_rfc3986(array_keys($params));
    $values = OAuthUtil::urlencode_rfc3986(array_values($params));
    $params = array_combine($keys, $values);

    // Parameters are sorted by name, using lexicographical byte value ordering.
    // Ref: Spec: 9.1.1 (1)
    uksort($params, 'strcmp');

    $pairs = array();
    foreach ($params as $parameter => $value) {
      if (is_array($value)) {
        // If two or more parameters share the same name, they are sorted by their value
        // Ref: Spec: 9.1.1 (1)
        natsort($value);
        foreach ($value as $duplicate_value) {
          $pairs[] = $parameter . '=' . $duplicate_value;
        }
      } else {
        $pairs[] = $parameter . '=' . $value;
      }
    }
    // For each parameter, the name is separated from the corresponding value by an '=' character (ASCII code 61)
    // Each name-value pair is separated by an '&' character (ASCII code 38)
    return implode('&', $pairs);
  }
}

//Xero specific signature class
class OAuthSignatureMethod_Xero extends OAuthSignatureMethod_RSA_SHA1 {
	protected $public_cert;
	protected $private_key;

	public function __construct($public_cert, $private_key) {
		$this->public_cert = $public_cert;
		$this->private_key = $private_key;
	}

	protected function fetch_public_cert(&$request) {
		return file_get_contents( $this->public_cert );
	}

	protected function fetch_private_cert(&$request) {
		return file_get_contents( $this->private_key );
	}
}


