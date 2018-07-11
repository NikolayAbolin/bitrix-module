<?php
use \Bitrix\Main\Config\Option;
use \Bitrix\Main\Application,
    \Bitrix\Main\Web\Uri,
    \Bitrix\Main\Web\HttpClient;
use  \Bitrix\Sale\Internals\OrderTable;

use Bitrix\Main\Config\Configuration;
use Bitrix\Sale\Order;

CModule::IncludeModule('sale');
CModule::IncludeModule('marschroute');

class CMarschroute
{
	const MODULE_ID = "marschroute";
	const MODULE_CODE = "MARSCHROUTE";
	// ������ ��������
	protected static $smStatuses = array(10, 11, 12, 13, 14, 15, 16, 18, 20, 21, 25, 35, 36, 50, 51) ;
	// ������ ���
	protected static $nds = array(0, 10, 18);
	// ������ �� ������� ������� ������
	protected static $myself = "CMarschroute::sync();";

	protected static $api_key;
	// ������� URL ��� �������
	protected static $base_url;

	// ��������� http-������� �������
	protected static $httpClientOptions = array(
		"waitResponse" => true,
		"socketTimeout" => 30,
		"streamTimeout" => 60,
		"version" => HttpClient::HTTP_1_1
	);

	// ��������� ������� ����������� ���������� ������ �� �������
    protected function getBitrixOrders($filter='all', $limit = 0) {

		$status_for_send =   Option::get(self::MODULE_ID, 'status_for_send');
		$pay_systems = Option::get( self::MODULE_ID, 'pay_systems' );

		// ������������ �������
    	switch ($filter):
			case 'all':
				$filter = array(
					'STATUS_ID' => $status_for_send
				);
				break;
			case 'not_sended':
				$filter = array(
					'STATUS_ID' => $status_for_send,
					'PROPERTY.CODE' => 'MARSCHROUTE_ORDER_ID',
					'=PROPERTY.VALUE' => ''
				);
				break;
			default: return false;
		endswitch;


		// ��������� ������ ������ � �������� ��������
        $settingsGetList = array(
            'select' => array(
                'ID'
            ),
            'filter' => $filter,
        );

        // ���� ����� ���������, �� ������������� ���
        if ($limit!==0) $settingsGetList['limit'] = $limit;

		$rsOrdersStatus = Order::getList( $settingsGetList );

		$ordersByStatus = array();
		$nds = Option::get(self::MODULE_ID, 'nds');

		// ������������ ������� ���������� ������
		while ($rsOrder = $rsOrdersStatus->Fetch()) {

			$order = Order::load($rsOrder['ID']);

			$propertyCollection = $order->loadPropertyCollection();

			$arPropColl = $propertyCollection->getArray();

			$arProp = array();
			foreach ($arPropColl['properties'] as $key => $val) {
				$arProp[$val['CODE']] = $val['VALUE'][0];
			}

			// payment_type
			$arProp['PAYMENT_TYPE'] = in_array($pay_systems[0], $pay_systems ) ? 1 : 2 ;
			// customer . id
			$arProp['CASTOMER_ID'] = $order->getUserId();

			$basket = Bitrix\Sale\Basket::loadItemsForOrder($order);

			$arProp['ITEMS'] = array();
			foreach ($basket as $item){
				array_push($arProp['ITEMS'], array(
					'item_id' => $item->getField('ID'),
					'name' => $item->getField('NAME'),
					'nds' => (int)$nds,
					'price' => (int)$item->getField('PRICE'),
					'quantity' => (int)$item->getField('QUANTITY')
				));
			}
			$ordersByStatus[$rsOrder['ID']] = $arProp;
		}
		return $ordersByStatus;
	}

	// ������ json ��� ��������
    protected function mapBitrixOrders() {

        $limit = Option::get( self::MODULE_ID, 'limit', 10 );

        $orders = self::getBitrixOrders('not_sended', $limit ); // � ��������

        $mapOrders = array();

        foreach ( $orders as $id => $val ) {
            list($firstname, $middlename, $lastname) = preg_split('/\s+/', $val['FIO']);
            if (empty($lastname)) $lastname = $middlename;
            $mapOrders[$id] =
                json_encode(
                array(
                    'order' => array(
                        'id' => $id,
                        'delivery_sum' => $val['MARSCHROUTE_DELIVERY_COST'],
                        'payment_type' => $val['PAYMENT_TYPE'], //++
                        'weight' => 1000,
                        'city_id' => $val['MARSCHROUTE_DELIVERY_KLDR'],
                        'place_id' => $val['MARSCHROUTE_PLACE_ID'],
                        'street' => $val['MARSCHROUTE_STREET'],
                        'building_1' => $val['MARSCHROUTE_HOUSE'],
                        'building_2' => $val['MARSCHROUTE_BULDING'],
                        'room' => $val['MARSCHROUTE_ROOM'],
                        'comment' => $val['MARSCHROUTE_DELIVERY_COMMENT'],
						'send_date' => $val['MARSCHROUTE_SEND_DATE'],
						'sm_order_id' => $val['MARSCHROUTE_ORDER_ID'],
                        'index' => $val['MARSCHROUTE_INDEX']

                    ),
                    'customer' => array(
                        'id' => $val['CASTOMER_ID'],
                        'firstname'  => $firstname,
                        'middlename' => $middlename,
                        'lastname' => $lastname,
						'phone' => $val['PHONE']

                    ),
                    'items' => $val['ITEMS']
                )
            );

        }

        return $mapOrders;
    }

    // ������� �������������
    public static function sync() {

        self::$api_key = Option::get(self::MODULE_ID, "api_key");
        self::$base_url = Option::get( self::MODULE_ID, "base_url");
        // �������� �������
		self::sendOrders();
		// ��������� �������� ��� �������, ��� ��� ������ ��������� ������� � ����������
		if ( Option::get( self::MODULE_ID, 'delivery_statuses_error' )) {
			self::takePutStatuses();
		}
		//echo 'sync OK!!!';
		return self::$myself;
    }

    // ��������� ������ ID ��������
    public static function getSmStatuses() {
		return self::$smStatuses;
	}

	// ��������� ������ ��������� ���
	public static function getNds() {
    	return self::$nds;
	}

	// ��������� � ��������� ������� ��� �������
	protected function takePutStatuses() {
		// ���� ��� �������� ��������
		$start = Option::get( self::MODULE_ID, 'last_update' );
		$end = date('d.m.Y');

		//��������� ���
		if (!empty( $start )) {
			$_start = new DateTime($start);
			$_end = new DateTime($end);
			// ���� ��������� ���������� ������ �������� ����, �� ���������� ���� �� 30 ���� �����
			$start = ($_start->diff($_end)->days > 30) ? $_end->modify('-30 days')->format('d.m.Y') : $start;
		}
		// ���� ������ 'last_update', �� ��������� � ���������� ���� ����� �������
		else $start = $end;

		// ��������� ������� ����� �������� �� �������� ������
		$delivery_statuses = json_decode( Option::get(self::MODULE_ID, 'delivery_statuses'), true );
		// ������������ URL-������� � ������
		$url = self::$base_url . self::$api_key . "/orders?filter[date_status]=$start%20-%20$end";

		try {
			// �������� http-������� � �������� �������
			$httpClient = new HttpClient(self::$httpClientOptions);
			$httpClient->query( HttpClient::HTTP_GET, $url );

			// ��������� ������
			$result = json_decode( $httpClient->getResult(), true );

			// ���� ����� �� JSON
			if (json_last_error()!=JSON_ERROR_NONE)
				throw new Exception('������ ���������� �������� ������');

			// ���������
			if (!$result['success'])
				throw new Exception( $result['comment'] );

			// ���� ���� ������ �� ������, �� ������������
			if ( !empty($result['data'])) {

				// ��������� ����������� �������

				// ID ����� ��������
				$our_delivery_id = \Bitrix\Sale\Delivery\Services\Manager::getIdByCode('MARSCHROUTE');

				foreach ( $result['data'] as $data_item ) {
					// ���� ������ ID �� ������� ��, �� ��� ����������
					if (empty($data_item['id'])) continue; ///

					// ������� ������ �� id
					$order = Order::load( $data_item['id'] );

					// ���� ��� ������ �� ������� �������, �� ��� ����������
					if (!$order) continue;

					// ��������� �������� //////

					// ��������� �������� � ������
					$shipmentCollection = $order->getShipmentCollection();

					// ����� ��������
					foreach ($shipmentCollection as $shipment) {
						// ������� ������ �������� � ����� DELIVERY_ID
						if ($shipment->getField( 'DELIVERY_ID' ) == $our_delivery_id )	{

							// ����� ������� ������� �� �������� ������

							foreach ($delivery_statuses as $status_id => $status_item)	{
								// ���� � ���������� ���� ������, �� ��� � ������
								if (in_array($data_item['status'], $status_item))	{
									$shipment->setField('STATUS_ID', $status_id);
									$order->save();
									break;
								}
							}
							break;
						}
					}
				}
			}
			// ��������� ��������� ���� ����������
			Option::set( self::MODULE_ID, 'last_update', $end);

		}
		catch (Exception $e){
			//echo $e->getMessage()."\n";
		}
	}

	// �������� �������
	protected function sendOrders(){
		//������������ URL-�������
		$url = self::$base_url . self::$api_key . '/order';

		// ����� ������� � put_body
		foreach (self::mapBitrixOrders() as $nOrder => $put_body){
			try {

				// �������� http-������� � �������� �������
				$httpClient = new HttpClient(self::$httpClientOptions);
				$httpClient->query(HttpClient::HTTP_PUT, $url, $put_body);
				// ��������� ������
				$result = json_decode( $httpClient->getResult(), true );

				// ��������� ������
				if (!$result['success']) {
                    // ����� ������
				    self::setOrderProp($nOrder, 'MARSCHROUTE_ERROR', "������ [code]:".$result['code']."\n".
                        $result['comment'] . "\n" .
                        json_encode( $result['errors']));

				    continue;
				}

				// ���� ���������� [id] � ���� ������
				if (isset($result['id'])) {

                    // ������ ������
                    self::setOrderProp($result['id'], 'MARSCHROUTE_ORDER_ID', $result['order_id'] );
                    // ������� ������
                    self::setOrderProp($result['id'], 'MARSCHROUTE_ERROR', '' );
				}
			}

			catch (Exception $e) {
				//echo $e->getMessage();
				//echo $e->getLine();
			}
		}
	}

	// ��������� �������� ���� �� ���� � ������ ������
    protected function setOrderProp($id_order, $code, $value ) {
	    $order = Order::load($id_order);
		$props = \Bitrix\Sale\Internals\OrderPropsTable::getList(array(
				'filter'=> array(
					'CODE' => $code,
					'PERSON_TYPE_ID' => $order->getPersonTypeId()
				)
			)
		);

		$prop = $props->fetchAll();

        $id_prop = $prop[0]['ID'];
        $propertyCollection = $order->getPropertyCollection();
        $propValue = $propertyCollection->getItemByOrderPropertyId($id_prop);
        $propValue->setValue($value);
        $order->save();

	}
}
