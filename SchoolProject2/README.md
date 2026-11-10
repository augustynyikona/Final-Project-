# VaxiCare - Web-Based Vaccination Scheduling and Notification System

VaxiCare is a comprehensive digital health platform designed to reduce missed pediatric immunizations by bridging the gap between guardians, clinical nurses, and hospital pharmacies. Originally designed for pediatric wards in Ndola, Zambia, this system ensures that vaccination schedules are adhered to and that healthcare facilities are clinically prepared for upcoming appointments.

## 🎯 Core Objectives

1. **Reduce Missed Vaccinations:** By providing guardians with an easy-to-use web interface to book, reschedule, and track their children's immunization cycles.
2. **Improve Clinical Readiness:** By integrating pharmacy inventory systems with clinical scheduling, ensuring that the required vaccines are always in stock before the patient arrives.
3. **Automate Communication:** By dispatching multi-stage automated SMS reminders to guardians at critical intervals (7 days, 3 days, 1 day, and 2 hours prior to the appointment).

---

## 👥 System Roles and Workflows

The system is built on a multi-role architecture tailored to specific healthcare workflows:

### 1. Guardian (Parent/Caretaker)
* **Registration & Tracking:** Guardians can register their children into the system and maintain a centralized record of their vaccination history.
* **Smart Scheduling:** A calendar interface allows guardians to book and reschedule appointments without needing to call or visit the clinic in person.
* **Reminders:** Receives automated, customized SMS alerts to prevent missed appointments.

### 2. Nurse (Clinical Staff)
* **Appointment Management:** Nurses have a dedicated dashboard to view the daily schedule of incoming patients, reducing overcrowding and wait times.
* **Patient Processing:** Nurses can document the administration of vaccines and mark appointments as 'Arrived' or 'No-Show'.
* **Requisitions:** Nurses can create direct requisitions to the pharmacy to request vaccine restocks based on the daily schedule.
* **SMS Dispatch Center:** Nurses have access to a multi-stage SMS dispatch interface to ensure reminders are sent out.

### 3. Pharmacist
* **Inventory Control:** Pharmacists can monitor real-time stock levels of various vaccines and medical supplies.
* **Demand Forecasting & Orders:** By reviewing clinical requisitions and upcoming appointments, pharmacists can predict vaccine demand and place orders proactively to prevent stock-outs.
* **Billing & Reports:** Access to financial and operational reporting to ensure smooth supply chain management.

---

## 🏗️ Technical Architecture

* **Frontend:** Responsive HTML, CSS, and JavaScript utilizing custom styling (Figma-inspired modern aesthetics).
* **Backend:** PHP handling routing, logic, and database interactions.
* **Database:** MySQL relational database (`vaxicare`) managing users, children, appointments, vaccines, requisitions, and SMS logs.
* **Integration:** Twilio API integration architecture for automated SMS dispatching.

---

## 🚀 Key Features

* **Pre-emptive Pharmacy Integration:** Unlike traditional systems where pharmacy and clinical scheduling are isolated, VaxiCare connects them. When appointments are booked, the potential demand is visible to the pharmacy.
* **Anti-Duplicate SMS Logging:** The system tracks which reminders (7-day, 3-day, etc.) have been sent to which appointment, preventing notification fatigue.
* **Role-Based Dashboards:** Unique, secure environments for each type of user, ensuring they only see information relevant to their workflow (Socio-Technical Systems Theory approach).

---

## 📌 Project Background
This system is the practical implementation of the research project: *"An Assessment of Effective Measures for the Reduction of the Employee Rate of Turnover Web-Based Vaccination Scheduling and Notification System with Pharmacy Integration"*. It utilizes a Design Science Research (DSR) methodology and is built to operate effectively in environments looking to transition from manual, paper-based scheduling to integrated digital health solutions.
