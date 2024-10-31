document.addEventListener('DOMContentLoaded', () => {
    if (typeof wc !== 'undefined' && wc.wcBlocksRegistry && wc.wcBlocksRegistry.registerPaymentMethod) {
        const registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;

        const CoinifyPaymentMethod = {
            name: 'coinify',
            label: 'Coinify Crypto Payment',
            ariaLabel: 'Pay with cryptocurrency using Coinify',
            content: wp.element.createElement('div', null, 'Pay with cryptocurrency through Coinify.'), // React элемент
            edit: wp.element.createElement('div', null, 'Pay with cryptocurrency through Coinify.'), // React элемент
            canMakePayment: () => true,
            supports: {
                showSavedCards: false,
            },
        };

        registerPaymentMethod(CoinifyPaymentMethod);
    } else {
        console.error('wc.wcBlocksRegistry или registerPaymentMethod не определен.');
    }
});
