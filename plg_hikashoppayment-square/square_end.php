<?php
/**
 * @package     Joomla.Site
 * @subpackage  plg_hikashoppayment_square
 * @version     1.0
 * @license     GNU General Public License version 3
 */

defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$doc = Factory::getDocument();

// Safely prepare JavaScript values.
$debug = (string) ($this->payment_params->debug ?? '0');
$checkoutUrl = $this->checkout_url;
$generalError = Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_GENERAL_ERROR');

$js = '
    function goToSquareGateway() {
        const button = document.getElementById("idevGoToSquareGateway");
        const debug = ' . json_encode($debug) . ';

        try {
            if (button) {
                button.disabled = true;
            }

            // Redirect to the generated Square Payment Link URL.
            window.location.href = ' . json_encode($checkoutUrl) . ';

        } catch (e) {
            if (button) {
                button.disabled = false;
            }

            if (debug === "1") {
                alert(e.message || e);
            }

            alert(' . json_encode($generalError) . ');
        }
    }

    // Wait until the DOM is ready before starting the redirect.
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function() {
            setTimeout(goToSquareGateway, 500);
        });
    } else {
        setTimeout(goToSquareGateway, 500);
    }
';

// Register JavaScript using Joomla Web Asset Manager when available.
$wa = $doc->getWebAssetManager();
$wa->addInlineScript($js);

?>

<div class="hikashop_square_end" id="hikashop_square_end">
    <fieldset>
        <legend>
            <?php echo Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_REDIRECTING'); ?>
        </legend>

        <div id="idevSquareCheckoutContainer"
             style="width:100%;margin:auto;text-align:center;">

            <span id="hikashop_square_end_message"
                  class="hikashop_square_end_message">
                <?php
                echo Text::sprintf(
                    'PLG_HIKASHOP_PAYMENT_SQUARE_PLEASE_WAIT',
                    $this->payment_name
                ) . '<br/>' . Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_IF_NOT_REDIRECTED');
                ?>
            </span>

            <br/><br/>

            <button type="button"
                    id="idevGoToSquareGateway"
                    class="btn btn-success btn-lg btn-block"
                    onclick="goToSquareGateway();">
                <?php echo Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_PROCEED_TO_PAYMENT'); ?>
            </button>

        </div>
    </fieldset>
</div>
