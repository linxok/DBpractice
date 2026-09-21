-- Тема: збережені процедури та функції в MySQL
-- Виконуйте: docker exec -i learn-mysql mysql -ustudent -pstudent --default-character-set=utf8mb4 < lessons/procedures_mysql.sql

USE sandbox;

-- 1. Процедура з вхідним і вихідним параметрами
DROP PROCEDURE IF EXISTS customer_orders_count;

DELIMITER //

CREATE PROCEDURE customer_orders_count(IN p_customer_id INT, OUT p_count INT)
BEGIN
    SELECT COUNT(*) INTO p_count FROM orders WHERE customer_id = p_customer_id;
END //

DELIMITER ;

CALL customer_orders_count(1, @cnt);
SELECT @cnt AS orders_of_customer_1;

-- 2. Процедура з циклом: наповнює таблицю числами
DROP TABLE IF EXISTS numbers;
CREATE TABLE numbers (n INT PRIMARY KEY);

DROP PROCEDURE IF EXISTS fill_numbers;

DELIMITER //

CREATE PROCEDURE fill_numbers(IN p_max INT)
BEGIN
    DECLARE i INT DEFAULT 1;
    WHILE i <= p_max DO
        INSERT INTO numbers (n) VALUES (i);
        SET i = i + 1;
    END WHILE;
END //

DELIMITER ;

CALL fill_numbers(10);
SELECT * FROM numbers ORDER BY n;

-- 3. Функція, що повертає значення
DROP FUNCTION IF EXISTS order_total;

DELIMITER //

CREATE FUNCTION order_total(p_order_id INT) RETURNS DECIMAL(10,2)
DETERMINISTIC READS SQL DATA
BEGIN
    DECLARE v_total DECIMAL(10,2);
    SELECT o.quantity * p.price INTO v_total
    FROM orders o
    JOIN products p ON p.id = o.product_id
    WHERE o.id = p_order_id;
    RETURN v_total;
END //

DELIMITER ;

SELECT order_total(1) AS total_of_order_1;

-- 4. Перегляд списку процедур і функцій
SELECT routine_name, routine_type
FROM information_schema.routines
WHERE routine_schema = 'sandbox';
