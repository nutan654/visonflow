# 🕶️ VisionFlow: Enterprise Optical CRM & E-Commerce Platform

![Docker](https://img.shields.io/badge/docker-%230db7ed.svg?style=for-the-badge&logo=docker&logoColor=white)
![Python](https://img.shields.io/badge/python-3670A0?style=for-the-badge&logo=python&logoColor=ffdd54)
![FastAPI](https://img.shields.io/badge/FastAPI-005571?style=for-the-badge&logo=fastapi)
![PHP](https://img.shields.io/badge/php-%23777BB4.svg?style=for-the-badge&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/mysql-%2300f.svg?style=for-the-badge&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/javascript-%23323330.svg?style=for-the-badge&logo=javascript&logoColor=%23F7DF1E)

**VisionFlow** is a containerized, microservice-driven prototype . It seamlessly merges a patient medical record system (CRM) with a dynamic eyewear e-commerce storefront.

## ✨ Key Features
* **Microservice Architecture:** Decoupled PHP/Apache backend and a Python/FastAPI predictive engine.
* **Relational Medical Database:** Secure, transaction-based SQL operations linking patient demographics to optical lens prescriptions.
* **Real-Time Data Visualization:** Interactive Chart.js dashboard powered by Python REST API.
* **Full CRUD Inventory API:** Single-page administrative dashboard for managing eyewear stock.
* **Japanese Localization (i18n):** Custom Vanilla JS engine allowing instant UI translation between English and Japanese via `localStorage`.
* **Containerized Deployment:** Fully Dockerized environment (Web, DB, AI) for guaranteed parity across development machines.

---

## 🏗️ System Architecture

```mermaid
graph TD
    Client[Frontend Client HTML/JS/CSS]
    
    subgraph Docker Network
        PHP[Web Server :80 Apache/PHP 8]
        Python[AI Engine :8000 Python/FastAPI]
        DB[(MySQL Database :3306)]
    end

    Client -- CRUD Requests --> PHP
    Client -- Fetch Trends --> Python
    PHP -- PDO PDO_MySQL --> DB
