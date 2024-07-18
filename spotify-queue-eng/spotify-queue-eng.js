jQuery(document).ready(function($) {
    $('#spotify-search-button').on('click', function() {
        var query = $('#spotify-search-input').val();
        $.ajax({
            url: spotifyQueue.ajax_url,
            method: 'POST',
            data: {
                action: 'spotify_queue_search',
                query: query
            },
            success: function(response) {
                if (response.success) {
                    var results = response.data.results;
                    var resultsContainer = $('#spotify-search-results');
                    resultsContainer.empty();
                    results.forEach(function(song) {
                        var songElement = $('<div>').addClass('spotify-song').append(
                            $('<img>').attr('src', song.album.images[0].url).addClass('spotify-cover'),
                            $('<div>').addClass('spotify-info').append(
                                $('<div>').addClass('spotify-title').text(song.name),
                                $('<div>').addClass('spotify-artist').text(song.artists.map(artist => artist.name).join(', '))
                            ),
                            $('<button>').addClass('spotify-add-button').html('+').on('click', function() {
                                addToQueue(song.uri, song.name, song.artists.map(artist => artist.name).join(', '));
                            })
                        );
                        resultsContainer.append(songElement);
                    });
                }
            }
        });
    });

    function addToQueue(songUri, songName, songArtist) {
        $.ajax({
            url: spotifyQueue.ajax_url,
            method: 'POST',
            data: {
                action: 'spotify_queue_add',
                uri: songUri,
                song_name: songName,
                song_artist: songArtist
            },
            success: function(response) {
                if (response.success) {
                    var message = 'The Song "' + songName + '" from ' + songArtist + ' was added to the queue. Please note that it may take some time for your song to play.';
                    var successMessage = $('<div>').addClass('spotify-success-message').text(message);
                    $('body').append(successMessage);
                    setTimeout(function() {
                        successMessage.fadeOut('slow', function() {
                            $(this).remove();
                        });
                    }, 10000);

                    $('#spotify-search-input').val('');
                    $('#spotify-search-results').empty();
                } else {
                    var errorData = response.data;
                    var errorMessage = $('<div>').addClass('spotify-error-message').html(
                                                                                         'The song "' + errorData.song_name + '" from ' + errorData.song_artist + ' could not be added to the queue. The same song can not be added within ' + errorData.cooldown_minutes + ' minutes. Please wait ' + errorData.remaining_time + ' minutes.'
                    );
                    $('body').append(errorMessage);
                    setTimeout(function() {
                        errorMessage.fadeOut('slow', function() {
                            $(this).remove();
                        });
                    }, 10000);
                }
            },
            error: function(xhr, status, error) {
                var errorMessage = $('<div>').addClass('spotify-error-message').text('Error: ' + error);
                $('body').append(errorMessage);
                setTimeout(function() {
                    errorMessage.fadeOut('slow', function() {
                        $(this).remove();
                    });
                }, 10000);
            }
        });
    }
});

