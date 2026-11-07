<?php

$host = "localhost";
$dbname = "divorce_db";
$username = "root";
$password = "";

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Create Dim_Year
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Dim_Year (
            year_id INT PRIMARY KEY,
            year_value INT NOT NULL UNIQUE
        )
    ");

    // Create Dim_Gender
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Dim_Gender (
            gender_id INT PRIMARY KEY AUTO_INCREMENT,
            gender_name VARCHAR(20) NOT NULL UNIQUE
        )
    ");

    // Create Dim_Age_Group
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Dim_Age_Group (
            age_group_id INT PRIMARY KEY AUTO_INCREMENT,
            age_group VARCHAR(50) NOT NULL UNIQUE
        )
    ");

    // Create Dim_Marriage_Duration
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Dim_Marriage_Duration (
            duration_id INT PRIMARY KEY AUTO_INCREMENT,
            marriage_duration VARCHAR(50) NOT NULL UNIQUE
        )
    ");

    // Create Dim_Reason
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Dim_Reason (
            reason_id INT PRIMARY KEY AUTO_INCREMENT,
            reason_name VARCHAR(150) NOT NULL UNIQUE
        )
    ");

    // Create Fact_Divorce_Age
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Fact_Divorce_Age (
            divorce_age_id BIGINT PRIMARY KEY AUTO_INCREMENT,

            year_id INT NOT NULL,
            gender_id INT NOT NULL,
            age_group_id INT NOT NULL,

            divorce_count INT NOT NULL,

            FOREIGN KEY (year_id)
                REFERENCES Dim_Year(year_id),

            FOREIGN KEY (gender_id)
                REFERENCES Dim_Gender(gender_id),

            FOREIGN KEY (age_group_id)
                REFERENCES Dim_Age_Group(age_group_id),

            UNIQUE (year_id, gender_id, age_group_id)
        )
    ");

    // Create Fact_Divorce_Reason
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS Fact_Divorce_Reason (
            divorce_reason_id BIGINT PRIMARY KEY AUTO_INCREMENT,

            year_id INT NOT NULL,
            duration_id INT NOT NULL,
            reason_id INT NOT NULL,

            divorce_count INT NOT NULL,

            FOREIGN KEY (year_id)
                REFERENCES Dim_Year(year_id),

            FOREIGN KEY (duration_id)
                REFERENCES Dim_Marriage_Duration(duration_id),

            FOREIGN KEY (reason_id)
                REFERENCES Dim_Reason(reason_id),

            UNIQUE (year_id, duration_id, reason_id)
        )
    ");

    echo "Tables created successfully.";

} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage();
}

?>