# Простой деплой в закрытый контур (бесплатно)

Ниже самый простой рабочий путь под ваш проект: **Oracle Cloud Always Free + Docker**.  
Парольную защиту оставляем в `index.php` (как вы и планировали).

## 1) Что нужно

- Аккаунт Oracle Cloud (Always Free)
- Ubuntu VM (1 OCPU / 1 GB RAM достаточно для старта)
- Открытый входящий порт `80` в Security List / NSG

## 2) Поднять сервер (один раз)

```bash
sudo apt-get update -y
sudo apt-get install -y git
git clone <URL_ВАШЕГО_РЕПО> ~/airtable
cd ~/airtable/deploy
bash oracle-free-setup.sh
```

После этого перезайдите в SSH (или `newgrp docker`).

## 3) Запуск сайта

```bash
cd ~/airtable
export AIRTABLE_PAT='pat_...'
export AIRTABLE_BASE_ID='appEAS1rPKpevoIel'
docker compose up -d --build
```

Сайт будет доступен по:

- `http://<PUBLIC_IP>/index.php`
- `http://<PUBLIC_IP>/manager.php`
- `http://<PUBLIC_IP>/churn.php`
- `http://<PUBLIC_IP>/churn_fact.php`

## 4) Обновление после изменений

```bash
cd ~/airtable
git pull
docker compose up -d --build
```

## 5) Полезные команды

```bash
docker compose ps
docker compose logs -f
docker compose down
```
