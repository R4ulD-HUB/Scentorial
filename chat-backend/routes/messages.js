const express = require('express');
const router = express.Router();
const Message = require('../models/Message');
const CryptoJS = require('crypto-js');

// Crear un nuevo chat
router.post('/chats', (req, res) => {
  const { chatId, password } = req.body;
  
  if (!chatId || !password) {
    return res.status(400).json({ error: 'Se requieren chatId y password' });
  }

  Message.createChat(chatId, password, (err, result) => {
    if (err) {
      if (err.message.includes('UNIQUE constraint failed')) {
        return res.status(409).json({ error: 'El chat ya existe' });
      }
      return res.status(500).json({ error: 'Error al crear el chat' });
    }
    
    res.status(201).json(result);
  });
});

// Verificar chat y contraseña
router.post('/chats/:chatId/verify', (req, res) => {
  const { chatId } = req.params;
  const { password } = req.body;
  
  if (!password) {
    return res.status(400).json({ error: 'Se requiere password' });
  }

  Message.verifyChatPassword(chatId, password, (err, isValid) => {
    if (err) {
      return res.status(404).json({ error: 'Chat no encontrado' });
    }
    
    if (!isValid) {
      return res.status(401).json({ error: 'Contraseña incorrecta' });
    }
    
    res.json({ valid: true });
  });
});

// Enviar mensaje
router.post('/chats/:chatId/messages', (req, res) => {
  const { chatId } = req.params;
  const { alias, message, password } = req.body;
  
  if (!alias || !message || !password) {
    return res.status(400).json({ error: 'Se requieren alias, message y password' });
  }

  // Primero verificar la contraseña
  Message.verifyChatPassword(chatId, password, (err, isValid) => {
    if (err) {
      return res.status(404).json({ error: 'Chat no encontrado' });
    }
    
    if (!isValid) {
      return res.status(401).json({ error: 'Contraseña incorrecta' });
    }
    
    // Guardar el mensaje
    Message.saveMessage(chatId, alias, message, (err, result) => {
      if (err) {
        return res.status(500).json({ error: 'Error al guardar el mensaje' });
      }
      
      res.status(201).json(result);
    });
  });
});

// Obtener mensajes
router.get('/chats/:chatId/messages', (req, res) => {
  const { chatId } = req.params;
  const { password } = req.query;
  const limit = parseInt(req.query.limit) || 50;
  
  if (!password) {
    return res.status(400).json({ error: 'Se requiere password' });
  }

  // Verificar la contraseña
  Message.verifyChatPassword(chatId, password, (err, isValid) => {
    if (err) {
      return res.status(404).json({ error: 'Chat no encontrado' });
    }
    
    if (!isValid) {
      return res.status(401).json({ error: 'Contraseña incorrecta' });
    }
    
    // Obtener mensajes
    Message.getMessages(chatId, limit, (err, messages) => {
      if (err) {
        return res.status(500).json({ error: 'Error al obtener mensajes' });
      }
      
      res.json(messages);
    });
  });
});

module.exports = router;