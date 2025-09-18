// Middleware para verificar API key (opcional)
const apiKeyAuth = (req, res, next) => {
  const apiKey = req.headers['x-api-key'];
  
  // Si tienes una API key definida en las variables de entorno
  if (process.env.API_KEY && apiKey !== process.env.API_KEY) {
    return res.status(401).json({ error: 'API key inválida' });
  }
  
  next();
};

module.exports = { apiKeyAuth };