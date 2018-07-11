<?php
namespace Sale\Handlers\Delivery;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Request;
use Bitrix\Sale\Delivery\CalculationResult;
use Bitrix\Sale\Delivery\Services\Base;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

class MarschrouteHandler extends Base
{



    public function __construct(array $initParams)
    {
        parent::__construct($initParams);
    }

    public static function getClassTitle()
    {
        return '�������� Marschroute';
    }

    public static function getClassDescription()
    {
        return '�������� Marschroute';
    }

    public function calculateConcrete(\Bitrix\Sale\Shipment $shipment)
    {
        $result = new CalculationResult();

        $request = Context::getCurrent()->getRequest();
        // ������������� ��������� �������� "��� ����", ������ ��� ������� ������� �������� ��������� ��� ������
        // ������� �� �������������
        //

        if ($request->isPost()) {
            \CModule::IncludeModule('sale');

            $person_type = intval($request->get('PERSON_TYPE'));

            $id_prop = \Bitrix\Sale\Internals\OrderPropsTable::getList(array(
                'filter' => array(
                    'PERSON_TYPE_ID' => $person_type,
                    'CODE' => 'MARSCHROUTE_DELIVERY_COST',
                ),
                'limit' => 1
            ))->fetch();

            $deliveryCost = ($request->isPost() && isset( $id_prop['ID'])) ? intval($request->get('ORDER_PROP_'.$id_prop['ID'])) : 0;

        } else {
            $deliveryCost = 0;
        };
        $result->setDeliveryPrice($deliveryCost);

        // ��������� ������ "�������".
        // ���� ���-�� �����, ��� ������� ��� ��� �������� - ������� issue / pull request
        $description = $result->getPeriodDescription();
        $description .= ' <a href="#" id="routewidget_window_open" class="routewidget_window_open">�������</a>';
        $result->setPeriodDescription($description);

        return $result;
    }

    protected function getConfigStructure()
    {
        return array(
            'MAIN' => array(
                'TITLE' => '���������',
                'DESCRIPTION' => '',
                'ITEMS' => array(
                    'PUBLIC_KEY' => array(
                        'TYPE' => 'STRING',
                        'NAME' => '��������� ����'
                    )
                ),
            )
        );
    }

    public function isCalculatePriceImmediately()
    {
        return false;
    }

    public static function whetherAdminExtraServicesShow()
    {
        return false;
    }
}