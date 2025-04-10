<?php
// 种火集结号 - 数据库连接类

class Database {
    private $conn;
    private static $instance;
    
    // 私有构造函数，防止直接创建对象
    private function __construct() {
        $this->connect();
    }
    
    // 单例模式，获取数据库连接实例
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // 连接数据库
    private function connect() {
        $this->conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
        
        // 检查连接
        if ($this->conn->connect_error) {
            die("数据库连接失败: " . $this->conn->connect_error);
        }
        
        // 设置字符集
        $this->conn->set_charset(DB_CHARSET);
    }
    
    // 获取数据库连接
    public function getConnection() {
        return $this->conn;
    }
    
    // 执行查询
    public function query($sql) {
        return $this->conn->query($sql);
    }
    
    // 准备语句
    public function prepare($sql) {
        return $this->conn->prepare($sql);
    }
    
    // 获取最后插入的ID
    public function getLastInsertId() {
        return $this->conn->insert_id;
    }
    
    // 获取受影响的行数
    public function getAffectedRows() {
        return $this->conn->affected_rows;
    }
    
    // 关闭数据库连接
    public function close() {
        if ($this->conn) {
            $this->conn->close();
        }
    }
    
    // 开始事务
    public function beginTransaction() {
        $this->conn->autocommit(false);
    }
    
    // 提交事务
    public function commit() {
        $this->conn->commit();
        $this->conn->autocommit(true);
    }
    
    // 回滚事务
    public function rollback() {
        $this->conn->rollback();
        $this->conn->autocommit(true);
    }
    
    // 转义字符串
    public function escapeString($string) {
        return $this->conn->real_escape_string($string);
    }
}
