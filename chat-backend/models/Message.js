const db = require('../config/database');
const CryptoJS = require('crypto-js');

class Message {
  // Crear un nuevo chat
  static createChat(chatId, password, callback) {
    const passwordHash = CryptoJS.SHA256(password).toString();
    
    db.run(
      'INSERT INTO chats (id, password_hash) VALUES (?, ?)',
      [chatId, passwordHash],
      function(err) {
        if (err) {
          callback(err);
        } else {
          callback(null, { id: chatId });
        }
      }
    );
  }

  // Verificar contraseña del chat
  static verifyChatPassword(chatId, password, callback) {
    db.get(
      'SELECT password_hash FROM chats WHERE id = ?',
      [chatId],
      (err, row) => {
        if (err) {
          callback(err);
        } else if (!row) {
          callback(new Error('Chat no encontrado'));
        } else {
          const inputHash = CryptoJS.SHA256(password).toString();
          const isValid = inputHash === row.password_hash;
          callback(null, isValid);
        }
      }
    );
  }

  // Guardar mensaje
  static saveMessage(chatId, alias, message, callback) {
    db.run(
      'INSERT INTO messages (chat_id, alias, message) VALUES (?, ?, ?)',
      [chatId, alias, message],
      function(err) {
        if (err) {
          callback(err);
        } else {
          callback(null, { id: this.lastID });
        }
      }
    );
  }

  // Obtener mensajes de un chat
  static getMessages(chatId, limit = 50, callback) {
    db.all(
      'SELECT * FROM messages WHERE chat_id = ? ORDER BY timestamp DESC LIMIT ?',
      [chatId, limit],
      (err, rows) => {
        if (err) {
          callback(err);
        } else {
          callback(null, rows);
        }
      }
    );
  }

  // Verificar si un chat existe
  static chatExists(chatId, callback) {
    db.get(
      'SELECT id FROM chats WHERE id = ?',
      [chatId],
      (err, row) => {
        if (err) {
          callback(err);
        } else {
          callback(null, !!row);
        }
      }
    );
  }
}

module.exports = Message;