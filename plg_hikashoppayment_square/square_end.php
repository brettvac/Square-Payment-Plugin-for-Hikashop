<?php
/**
 * @package    Square Payment Plugin for HikaShop
 * @license    GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */
defined('_JEXEC') or die('Restricted access');

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;

$js = '
    function goToSquareGateway() {
        try {
            var debug = "'. @$this->payment_params->debug .'";
            jQuery("#idevGoToSquareGateway").prop("disabled", true);
            
            // Redirect the window to the newly generated Square Payment Link URL
            window.location.href = "'. $this->checkout_url .'";
            
        } catch(e) {
            jQuery("#idevGoToSquareGateway").prop("disabled", false);
            if(debug == "1") {
                if(e.message) {
                    alert(e.message);
                } else {
                    alert(e);
                }
            }
            alert("' . addslashes(Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_GENERAL_ERROR')) . '");
        }
    }
    jQuery(document).ready(function() {
        goToSquareGateway();
    });
';

$doc = Factory::getDocument();
hikashop_loadJsLib('jquery');
$doc->addScriptDeclaration($js);

?>

<div class="hikashop_square_end" id="hikashop_square_end">
    <fieldset>
        <legend><?php echo Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_REDIRECTING'); ?></legend>
        <div id="idevSquareCheckoutContainer" style="width:100%;margin:auto;text-align:center;">
            
            <span id="hikashop_square_end_message" class="hikashop_square_end_message">
                <?php echo Text::sprintf('PLG_HIKASHOP_PAYMENT_SQUARE_PLEASE_WAIT', $this->payment_name) . '<br/>' . Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_IF_NOT_REDIRECTED'); ?>
            </span>
            
            <br/><br/>
            
            <button id="idevGoToSquareGateway" class="btn btn-success btn-lg btn-block" onclick="goToSquareGateway();">
                <?php echo Text::_('PLG_HIKASHOP_PAYMENT_SQUARE_PROCEED_TO_PAYMENT'); ?>
            </button>
            
        </div>
    </fieldset>
</div>