-- Sweet Bakers — seed data ported from sweet-bakers-console.html's seed().
-- Everything here is date-relative to CURDATE()/NOW() so batches/orders/wastage
-- look sensible whenever this is actually loaded (the prototype pinned "today"
-- to a fixed date; a real deployment can't do that).
--
-- Demo user accounts (admin/manager/baker/store/one seed customer) are inserted
-- by database/migrate.php instead of here, because their passwords must be
-- hashed with PHP's password_hash() — see README for the seeded credentials.
-- Run schema.sql, then migrate.php (which seeds users, then runs this file).

-- ---------------------------------------------------------------
-- suppliers
-- ---------------------------------------------------------------
INSERT INTO suppliers (id,name,contact,email,lead_days,supplies_summary) VALUES
('S01','Ceylon Flour Mills','011-2456789','orders@ceylonflour.lk',3,'Flour, Yeast'),
('S02','Kandy Dairy Co.','011-2987654','sales@kandydairy.lk',2,'Butter, Milk, Eggs'),
('S03','Lanka Sugar Ltd.','077-1234567','hello@lankasugar.lk',4,'Sugar, Chocolate, Vanilla');

-- ---------------------------------------------------------------
-- ingredients
-- ---------------------------------------------------------------
INSERT INTO ingredients (id,name,uom,unit_cost,reorder_level,supplier_id,used_last_7d) VALUES
('IG01','Wheat flour','kg',180,40,'S01',86),
('IG02','Sugar','kg',240,25,'S03',22),
('IG03','Butter','kg',1450,12,'S02',14),
('IG04','Eggs','pc',32,120,'S02',310),
('IG05','Yeast','kg',1200,4,'S01',2.4),
('IG06','Milk','L',290,20,'S02',24),
('IG07','Dark chocolate','kg',2100,5,'S03',6),
('IG08','Vanilla essence','L',3800,1,'S03',0.9);

-- ---------------------------------------------------------------
-- inventory batches (expiry dates relative to CURDATE())
-- ---------------------------------------------------------------
INSERT INTO batches (id,ingredient_id,supplier_id,received_qty,qty_on_hand,unit_cost,expiry_date) VALUES
('B1001','IG01','S01',80,52,180,DATE_ADD(CURDATE(), INTERVAL 9 DAY)),
('B1002','IG01','S01',60,18,176,DATE_ADD(CURDATE(), INTERVAL 2 DAY)),
('B1003','IG02','S03',40,31,240,DATE_ADD(CURDATE(), INTERVAL 60 DAY)),
('B1004','IG03','S02',20,6,1450,DATE_ADD(CURDATE(), INTERVAL 4 DAY)),
('B1005','IG04','S02',300,140,32,DATE_ADD(CURDATE(), INTERVAL 12 DAY)),
('B1006','IG05','S01',8,2.4,1200,DATE_ADD(CURDATE(), INTERVAL 40 DAY)),
('B1007','IG06','S02',30,9,290,DATE_ADD(CURDATE(), INTERVAL 1 DAY)),
('B1008','IG06','S02',24,9,288,DATE_ADD(CURDATE(), INTERVAL 6 DAY)),
('B1009','IG07','S03',12,9,2100,DATE_ADD(CURDATE(), INTERVAL 50 DAY)),
('B1010','IG08','S03',2,0.8,3800,DATE_ADD(CURDATE(), INTERVAL 120 DAY));

-- ---------------------------------------------------------------
-- products
-- ---------------------------------------------------------------
INSERT INTO products (id,name,emoji,price,shelf_stock,description,avg_weekly_sales) VALUES
('P01','Egg bun','🥯',120,48,'Sri Lankan classic, savoury egg filling.',55),
('P02','Sandwich bread','🍞',180,24,'Soft white loaf, baked every morning.',30),
('P03','Butter cookie','🍪',60,60,'Crisp, buttery and lightly sweet.',80),
('P04','Cream bun','🧁',140,30,'Soft bun with vanilla cream filling.',40),
('P05','Chocolate cake (1kg)','🎂',2400,5,'Rich dark chocolate celebration cake.',4),
('P06','Croissant','🥐',190,36,'Flaky, laminated butter pastry.',45);

-- ---------------------------------------------------------------
-- recipe lines
-- ---------------------------------------------------------------
INSERT INTO recipe_lines (product_id,ingredient_id,qty_per_unit) VALUES
('P01','IG01',.06),('P01','IG04',1),('P01','IG02',.01),('P01','IG05',.002),
('P02','IG01',.5),('P02','IG05',.008),('P02','IG06',.15),('P02','IG02',.02),
('P03','IG01',.02),('P03','IG03',.015),('P03','IG02',.012),('P03','IG04',.1),
('P04','IG01',.07),('P04','IG03',.02),('P04','IG06',.03),('P04','IG02',.015),
('P05','IG01',.4),('P05','IG07',.3),('P05','IG02',.35),('P05','IG04',4),('P05','IG03',.25),('P05','IG08',.01),
('P06','IG01',.08),('P06','IG03',.06),('P06','IG04',.5),('P06','IG05',.002);

-- ---------------------------------------------------------------
-- purchase order (one already Sent, awaiting receipt)
-- ---------------------------------------------------------------
INSERT INTO purchase_orders (id,supplier_id,status,is_auto,total,eta_days,created_by,created_at,sent_at) VALUES
('PO118','S03','Sent',0,9600,4,
  (SELECT id FROM users WHERE email='manager@sweetbakers.lk'),
  DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY));
INSERT INTO purchase_order_lines (po_id,ingredient_id,qty,unit_cost) VALUES
('PO118','IG02',40,240);

-- ---------------------------------------------------------------
-- wastage log (batch_id left NULL — these reference batches already fully
-- consumed before this seed snapshot, matching the prototype's historical log)
-- ---------------------------------------------------------------
INSERT INTO wastage (id,ingredient_id,batch_id,qty,reason,cost,is_auto,logged_at) VALUES
('W001','IG01',NULL,6,'Over-Production',1080,0,DATE_SUB(NOW(), INTERVAL 3 DAY)),
('W002','IG06',NULL,4,'Expired',1160,1,DATE_SUB(NOW(), INTERVAL 2 DAY)),
('W003','IG03',NULL,1.5,'Damaged/Spoiled',2175,0,DATE_SUB(NOW(), INTERVAL 1 DAY));

-- ---------------------------------------------------------------
-- one completed production run (this morning)
-- ---------------------------------------------------------------
INSERT INTO production_runs (id,run_by,run_at,status) VALUES
(1, (SELECT id FROM users WHERE email='baker@sweetbakers.lk'), DATE_SUB(NOW(), INTERVAL 6 HOUR), 'Completed');
INSERT INTO production_run_lines (run_id,product_id,qty) VALUES
(1,'P01',50),(1,'P06',40),(1,'P02',30);

-- ---------------------------------------------------------------
-- orders + lines + timeline
-- ---------------------------------------------------------------
INSERT INTO orders (id,customer_id,customer_name,phone,total,status,order_type,mode,address,payment_method,note,created_at) VALUES
('ORD-5012',(SELECT id FROM users WHERE email='amaya@gmail.com'),'Amaya S.','077-5551234',3240,'Preparing','Online','Delivery',
  '12, Galle Road, Colombo 03','Cash on delivery','Birthday — please add candles',DATE_SUB(NOW(), INTERVAL 3 HOUR)),
('ORD-5011',NULL,'Hilton Colombo','011-2492492',7600,'Pending','Online','Delivery',
  '2, Sir C. Gardiner Mw, Colombo 02','Invoice (corporate)',NULL,DATE_SUB(NOW(), INTERVAL 4 HOUR)),
('ORD-5008',NULL,'Walk-in','—',1080,'Delivered','POS','Pickup',
  'In-store','Cash',NULL,DATE_SUB(NOW(), INTERVAL 5 HOUR));

INSERT INTO order_lines (order_id,product_id,qty,unit_price) VALUES
('ORD-5012','P05',1,2400),('ORD-5012','P04',6,140),
('ORD-5011','P06',40,190),
('ORD-5008','P02',2,180),('ORD-5008','P01',6,120);

INSERT INTO order_timeline (order_id,event,happened_at) VALUES
('ORD-5012','Order placed online',DATE_SUB(NOW(), INTERVAL 3 HOUR)),
('ORD-5012','Confirmed — preparing',DATE_SUB(NOW(), INTERVAL '2 55' HOUR_MINUTE)),
('ORD-5011','Order placed online',DATE_SUB(NOW(), INTERVAL 4 HOUR)),
('ORD-5008','POS sale completed',DATE_SUB(NOW(), INTERVAL 5 HOUR));

-- ---------------------------------------------------------------
-- 7-day sales rollup (feeds the dashboard sales chart; last row = today)
-- ---------------------------------------------------------------
INSERT INTO sales_daily (sale_date,total) VALUES
(DATE_SUB(CURDATE(), INTERVAL 6 DAY),28400),
(DATE_SUB(CURDATE(), INTERVAL 5 DAY),34100),
(DATE_SUB(CURDATE(), INTERVAL 4 DAY),46800),
(DATE_SUB(CURDATE(), INTERVAL 3 DAY),51200),
(DATE_SUB(CURDATE(), INTERVAL 2 DAY),30900),
(DATE_SUB(CURDATE(), INTERVAL 1 DAY),33600),
(CURDATE(),21450);

-- ---------------------------------------------------------------
-- starter notifications
-- ---------------------------------------------------------------
INSERT INTO notifications (type,icon,title,message,category,is_read,created_at) VALUES
('bad','⚑','Wheat flour near reorder','70 kg on hand · reorder at 40 kg','inventory',0,DATE_SUB(NOW(), INTERVAL '5 50' HOUR_MINUTE)),
('bad','⚑','Butter below reorder level','6 kg on hand · reorder at 12 kg','inventory',0,DATE_SUB(NOW(), INTERVAL '5 50' HOUR_MINUTE)),
('info','☰','New online order ORD-5011','Hilton Colombo · Rs. 7,600','orders',0,DATE_SUB(NOW(), INTERVAL 4 HOUR)),
('warn','♺','Milk batch B1007 expires tomorrow','9 L remaining — use first or discount','inventory',0,DATE_SUB(NOW(), INTERVAL '2 20' HOUR_MINUTE));

-- ---------------------------------------------------------------
-- id sequence counters (next free number per prefix)
-- ---------------------------------------------------------------
INSERT INTO id_sequences (name,next_value) VALUES
('ingredient',9),
('supplier',4),
('product',7),
('po',119),
('ord',5013),
('batch',1011),
('waste',4);
