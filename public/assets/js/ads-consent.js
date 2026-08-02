(function () {
    'use strict';

    const bar = document.getElementById('fhAdsConsent');
    if (!bar) return;

    window.dataLayer = window.dataLayer || [];
    function gtag() {
        window.dataLayer.push(arguments);
    }

    const consent = {
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        analytics_storage: 'denied',
    };
    gtag('consent', 'default', consent);

    const storageKey = 'fh_ads_consent';
    function showSlots(visible) {
        document.querySelectorAll('.ad-slot--adsense').forEach((slot) => {
            slot.hidden = !visible;
        });
    }

    function loadAds() {
        gtag('consent', 'update', {
            ad_storage: 'granted',
            ad_user_data: 'granted',
            ad_personalization: 'granted',
        });
        showSlots(true);
        const loaderSource = bar.dataset.loaderSrc || '';
        if (!loaderSource) return;
        const script = document.createElement('script');
        script.async = true;
        script.crossOrigin = 'anonymous';
        script.src = loaderSource;
        document.head.appendChild(script);
    }

    let decision = null;
    try {
        decision = window.localStorage.getItem(storageKey);
    } catch (_error) {
        decision = null;
    }

    if (decision === 'granted') {
        loadAds();
        return;
    }
    showSlots(false);
    if (decision === 'denied') return;
    bar.hidden = false;

    function decide(value) {
        try {
            window.localStorage.setItem(storageKey, value);
        } catch (_error) {
            // The decision still applies to the current page.
        }
        bar.hidden = true;
        if (value === 'granted') loadAds();
    }

    document.getElementById('fhAdsConsentAccept')?.addEventListener('click', () => {
        decide('granted');
    });
    document.getElementById('fhAdsConsentDecline')?.addEventListener('click', () => {
        decide('denied');
    });
}());
