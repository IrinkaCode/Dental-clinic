/**
 * Юридические страницы (consent.html): подставляет в текст адрес, телефон
 * и email клиники из базы. Эти данные администратор меняет в панели,
 * в разделе «Контакты клиники», поэтому текст согласия всегда актуален.
 */
'use strict';

document.addEventListener('DOMContentLoaded', async () => {
    const targets = document.querySelectorAll('[data-clinic]');
    try {
        const { clinic } = await api.get('clinic.php');
        targets.forEach((el) => {
            if (clinic[el.dataset.clinic]) {
                el.textContent = clinic[el.dataset.clinic];
            }
        });
    } catch {
        // без контактов текст остаётся читаемым
    }
});
