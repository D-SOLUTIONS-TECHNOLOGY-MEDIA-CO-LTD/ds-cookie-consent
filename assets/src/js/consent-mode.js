/**
 * Pushes a consent decision to Google Consent Mode v2, the dataLayer, Meta Pixel and DOM events.
 */

const grant = (b) => (b ? 'granted' : 'denied');

/**
 * Re-send the stored choice as a Consent Mode "update" when a page loads. The head script already set the defaults from
 * the cookie; another plugin that prints its own (later) default, such as Site Kit, would otherwise override them.
 * Only the gtag call: no event, no log.
 */
export function restoreConsentMode(rec) {
  try {
    const a = !!rec.c.analytics;
    const m = !!rec.c.marketing;
    if (typeof window.gtag !== 'function') return;
    window.gtag('consent', 'update', {
      analytics_storage: grant(a),
      ad_storage: grant(m),
      ad_user_data: grant(m),
      ad_personalization: grant(m),
    });
  } catch (e) {
    /* ignore */
  }
}

/**
 * @param {Object} cfg  client data
 * @param {Object} rec  consent record {v,id,t,c,s,src}
 */
export function applyConsent(cfg, rec) {
  const a = !!rec.c.analytics;
  const m = !!rec.c.marketing;

  try {
    window.dataLayer = window.dataLayer || [];
    if (typeof window.gtag !== 'function') {
      window.gtag = function () {
        window.dataLayer.push(arguments);
      };
    }
    window.gtag('consent', 'update', {
      analytics_storage: grant(a),
      ad_storage: grant(m),
      ad_user_data: grant(m),
      ad_personalization: grant(m),
    });
    window.gtag('set', 'ads_data_redaction', !m);
    window.dataLayer.push({ event: 'dscc_consent_update', dscc_analytics: a, dscc_marketing: m, dscc_services: rec.s });
  } catch (e) {
    /* never break the site */
  }

  try {
    if (typeof window.fbq === 'function') window.fbq('consent', m ? 'grant' : 'revoke');
  } catch (e) {
    /* ignore */
  }

  const detail = { analytics: a, marketing: m, services: rec.s, id: rec.id, v: rec.v };
  try {
    document.dispatchEvent(new CustomEvent('dscc:consent', { detail }));
  } catch (e) {
    /* ignore */
  }
}
