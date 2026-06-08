Local browser for interacting with LMStudio server or exo server.

This code automatically creates a sqlite DB to save chats

<img width="1453" height="813" alt="image" src="https://github.com/user-attachments/assets/1fe4fff3-4532-4764-ad85-bcfcf7c57b80" />

For LLM Studio -- 
change the line "    $ch = curl_init('http://localhost:1234/v1/chat/completions');" to another address if you aren't hosting on your local machine

For exo --
change the line "$ch = curl_init('http://localhost:1234/v1/chat/completions');" to another address if you aren't hosting on your local machine
change the line " 'model'    => 'local-model'," to the model name you are running with exo.   exo requires a model name in this field.
