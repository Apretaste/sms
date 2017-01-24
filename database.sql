CREATE TABLE IF NOT EXISTS `_sms_messages` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `sent` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `email` char(100) NOT NULL,
  `code` varchar(5) NOT NULL,
  `number` varchar(15) NOT NULL,
  `text` char(160) NOT NULL,
  `price` float NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB  DEFAULT CHARSET=latin1 AUTO_INCREMENT=6;
