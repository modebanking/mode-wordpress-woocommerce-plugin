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
    <script src="https://widget.paywithmode.com/mode-dropin-ui.min.js"></script>
    <script type="text/javascript">
      (async function ($) {
        const data = {
          'amount': '<? echo $order->total ?>',
          'currency': '<? echo $order->currency ?>',
          'orderRef': Date.now(),
          'statementDescriptor': '<? echo $order->order_key ?>',
          'description': '<? echo $order->order_key ?>'
        }

        console.log(data, 'data')

        const { signature } = await $.ajax({
          method: 'POST',
          crossDomain: true,
          url: '/wp-json/mode/v1/payment-signature',
          data: JSON.stringify(data),
          dataType: 'json'
        })
        console.log(signature)

        document.write(`
          <mode-dropin-ui
            mid="607ee348bdbf7b336347e3d2"
            amount="${data.amount}"
            currency="${data.currency}"
            order-ref="${data.order_ref}"
            statement-descriptor="${data.statement_descriptor}"
            description="${data.description}"
            payment-signature="${signature}"
          >
          </mode-dropin-ui>
          <div id="success" style="display: none">
            Yay, you've paid!
          </div>
        `)

        const handleSuccess = () => {
          document.getElementById('success').style.display = 'block'
        }
      })(jQuery)
    </script>
