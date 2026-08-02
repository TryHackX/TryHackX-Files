(function () {
    'use strict';

    document.querySelectorAll('ins.adsbygoogle:not([data-fh-initialized])').forEach((slot) => {
        slot.dataset.fhInitialized = '1';
        window.adsbygoogle = window.adsbygoogle || [];
        window.adsbygoogle.push({});
    });
}());
