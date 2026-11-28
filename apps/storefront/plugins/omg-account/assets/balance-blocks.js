/* OMGGamer: "Account balance" payment method in the block checkout. Availability is decided on the server
   (the method is only offered when the balance covers the whole order). */
(function () {
  var registry = window.wc && window.wc.wcBlocksRegistry;
  var settings = window.wc && window.wc.wcSettings && window.wc.wcSettings.getSetting ? window.wc.wcSettings.getSetting('omg_balance_data', {}) : {};
  if (!registry || !window.wp || !window.wp.element) return;
  var el = window.wp.element.createElement;
  var decode = window.wp.htmlEntities ? window.wp.htmlEntities.decodeEntities : function (s) { return s; };
  var title = decode(settings.title || 'Account balance');
  var Content = function () { return el('div', { className: 'omg-balance-desc' }, decode(settings.description || '')); };
  registry.registerPaymentMethod({
    name: 'omg_balance',
    label: el('span', { className: 'omg-balance-label' }, title),
    ariaLabel: title,
    content: el(Content, null),
    edit: el(Content, null),
    canMakePayment: function () { return true; },
    supports: { features: settings.supports || ['products'] }
  });
})();
