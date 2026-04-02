<?php
/**
 * Скопируйте в config.php и заполните, либо задайте AIRTABLE_PAT в окружении веб-сервера.
 * Файл config.php не коммитьте.
 */
return [
    'airtable_pat' => '',
    'airtable_base_id' => 'appEAS1rPKpevoIel',
    // Необязательно: tbl… для таблицы «ДЗ». Пусто — поиск таблицы «🔸Debt … copy» через Meta API.
    'airtable_dz_table_id' => '',
    // Необязательно: viw… вида «🔸Debt (15,30,60,90)(Демидова) copy» в таблице ДЗ (URL …/tbl…/viw…).
    'airtable_dz_view_id' => '',
    // Необязательно: tbl… таблицы «Клиенты» (или отдельной таблицы CS ALL). Пусто — поиск таблицы «CS ALL» через Meta API.
    'airtable_cs_table_id' => '',
    // Необязательно: viw… вида «CS ALL» внутри таблицы (см. URL …/tbl…/viw… в Airtable).
    'airtable_cs_view_id' => '',
    // Необязательно: tbl… той же «Клиенты» или отдельной таблицы CHURN.
    'airtable_churn_table_id' => '',
    // Необязательно: viw… вида «❤️ CHURN Prediction ☠️».
    'airtable_churn_view_id' => '',
    // Доп. таблицы для выпадающего «Источник» (через запятую tbl…), если нет scope schema.bases:read у токена.
    'airtable_extra_source_table_ids' => '',
    // Вид «оплачено» в таблице ДЗ (viw…), для графика оплат по неделям. Пусто — дефолт ♥️Оплачено CSM.
    'airtable_paid_view_id' => '',
    // Авторизация в приложении (без БД): включается при заданных auth_username + auth_password(_hash).
    // Можно принудительно отключить/включить: false/true или '' для автоповедения.
    'auth_enabled' => '',
    'auth_username' => '',
    // Вариант 1: пароль в открытом виде (проще, но хуже по безопасности).
    'auth_password' => '',
    // Вариант 2 (рекомендуется): хэш из password_hash('пароль', PASSWORD_DEFAULT).
    // Если задан auth_password_hash — он приоритетнее auth_password.
    'auth_password_hash' => '',
    // Необязательно: общий секрет для вызова churn_api.php / churn_fact_api.php без CSRF
    // (переменная окружения DASHBOARD_API_SECRET имеет приоритет). См. GUIDE.md.
    'api_secret' => '',
    // Необязательно: Google AI (Gemini) для страницы ai_insights.php — переменная DASHBOARD_GEMINI_API_KEY предпочтительнее.
    'gemini_api_key' => '',
];
