<?php
/**
* Copyright (c) 2019-2020 Mode
*
* Licensed under the Apache License, Version 2.0 (the "License");
* you may not use this file except in compliance with the License.
* You may obtain a copy of the License at
*
* http://www.apache.org/licenses/LICENSE-2.0
*
* Unless required by applicable law or agreed to in writing, software
* distributed under the License is distributed on an "AS IS" BASIS,
* WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
* See the License for the specific language governing permissions and
* limitations under the License.
*
*/

/**
* @var Mode_Gateway $gateway
* @var WC_Abstract_Order $order
*/ ?>
    <script src="https://widget.modeforbusiness.com/mode-dropin-ui.min.js"></script>
    <script type="text/javascript">
      (async function ($) {
        <? $items = $order->get_items();
          $orderList = array();

          foreach( $items as $item_id => $item ) {
            array_push($orderList, $item->get_name().' x '.$item->get_quantity());
          }

          $orderItems = join(', ', $orderList);
        ?>

        var data = {
          'amount': '<? echo $order->total ?>',
          'currency': '<? echo $order->currency ?>',
          'statementDescriptor': '<? echo $order->order_key ?>',
          'description': '<? echo $orderItems ?>',
          'orderRef': '<? echo $orderItems ?>'
        };

        var { signature } = await $.ajax({
          method: 'POST',
          crossDomain: true,
          url: '/wp-json/mode/v1/payment-signature',
          data: JSON.stringify(data),
          dataType: 'json'
        });

        $('.woocommerce').append(`
          <center><mode-dropin-ui
            mid="<? echo get_option('mode_merchant_id') ?>"
            amount="${data.amount}"
            currency="${data.currency}"
            order-ref="${data.orderRef}"
            statement-descriptor="${data.statementDescriptor}"
            description="${data.description}"
            payment-signature="${signature}"
            class="col-12 col-sm-8 col-md-6"
            style="display: inline-block;"
          >
          </mode-dropin-ui></center>`);

        var pollForSuccess = function (response) {
          setTimeout(async function () {
            await $.ajax({
              method: 'POST',
              url: '/wp-json/mode/v1/check-payment',
              data: JSON.stringify({
                statementDescriptor: data.statementDescriptor
              }),
              success: async function (result) {
                if (result.status !== 'processing') {
                  pollForSuccess();
                } else {
                  window.location.href = '/checkout/order-received';
                }
              }
            });
          }, 1500);
        };

      pollForSuccess();
      })(jQuery)
    </script>
