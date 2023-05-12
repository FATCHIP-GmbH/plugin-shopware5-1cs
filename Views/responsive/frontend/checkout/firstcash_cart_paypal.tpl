{extends file="parent:frontend/checkout/cart.tpl"}

{block name="frontend_checkout_actions_confirm"}
    {$smarty.block.parent}
    {if $sBasket.content}
        <div class="button--container">
            <a href="{url controller='FatchipFCSPaypalExpress' action='gateway' shipping=$sShippingcosts paymentId=$sPayment['id'] dispatch=$sDispatch['id']}">
                <img src="{$fatchipFCSPaymentPaypalButtonUrl}">
            </a>
        </div>
    {/if}
{/block}
