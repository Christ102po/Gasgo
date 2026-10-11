-- Import after schema.sql while if0_42856614_gasgo is selected.

INSERT INTO gas_stations
(name,address,barangay,description,latitude,longitude,operating_hours,diesel_price,unleaded_price,premium_price,lpg_available,lpg_price,contact_number,status)
VALUES
('Sample Siargao Fuel Station','General Luna, Siargao','General Luna','Sample development record — replace with verified station information.',9.7832000,126.1586000,'06:00 AM - 10:00 PM',62.00,64.00,67.00,1,900.00,'000-000-0000','Approved');

INSERT INTO faqs (question, answer) VALUES
('What is GasGo?','GasGo is a web-based gasoline-station locator for Siargao Island.'),
('Do I need an account to find stations?','No. Public station browsing, searching, favorites, and directions can be used without registration.'),
('Can I install GasGo on my phone?','Yes. When your browser supports installation, GasGo can be added to your home screen as a PWA.'),
('Are fuel prices guaranteed to be current?','Fuel information depends on the latest approved station data. Always verify prices and availability with the station when necessary.');
