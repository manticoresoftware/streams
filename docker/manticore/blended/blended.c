//
// Copyright (c) 2017-2020, Manticore Software LTD (http://manticoresearch.com)
// Copyright (c) 2011-2013, Andrew Aksyonoff
// Copyright (c) 2011-2013, Sphinx Technologies Inc
// All rights reserved
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License. You should have
// received a copy of the GPL license along with this program; if you
// did not, you can find it at http://www.gnu.org/
//

#include "sphinxudf.h"

#include <memory.h>
#include <stdlib.h>
#include <string.h>
#include "utarray.h"

#ifdef _MSC_VER
#define snprintf _snprintf
#define DLLEXPORT __declspec(dllexport)
#else
#define DLLEXPORT
#endif

#define MAX_TOKEN_LEN 256
#define EXPANSION_LIMIT 32
#define NUM_BLENDED 7
#define BLENDED_CHAR_END 0x60

char all_blended[NUM_BLENDED] = "#$&'-@_";

typedef struct blenddata
{
	char		base_token[MAX_TOKEN_LEN];
	char		result_token[MAX_TOKEN_LEN];
	int			base_token_len;
	int			blend_flags[NUM_BLENDED];
	int			blend_ids[MAX_TOKEN_LEN];
	int			result_id;
	int			is_blended;
	int			is_blended_part;
	UT_array *	tokens;
} BLENDDATA;


typedef struct tokeninfo
{
	char 		token[MAX_TOKEN_LEN];
	int			delta;
	int			source_index;
} TOKENINFO;


void tokeninfo_copy ( void * dst, const void * src )
{
	TOKENINFO * token_dst = (TOKENINFO*)dst;
	TOKENINFO * token_src = (TOKENINFO*)src;
	token_dst->delta = token_src->delta;
	token_dst->source_index = token_src->source_index;
	strncpy ( token_dst->token, token_src->token, MAX_TOKEN_LEN );
}


UT_icd tokeninfo_icd = {sizeof(TOKENINFO), NULL, tokeninfo_copy, NULL};


char * create_string ( BLENDDATA * data )
{
	int i;
	int dest=0;

	for ( i = 0; i < data->base_token_len; i++ )
	{
		if ( data->blend_ids[i] != -1 )
		{
			if ( data->blend_flags[data->blend_ids[i]]==0 )		// keep the character
			{
				data->result_token[dest++]=data->base_token[i];
			} else if ( data->blend_flags[data->blend_ids[i]]==1 )	// replace with space
			{
				if ( dest )
					data->result_token[dest++]=' ';
			}
		} else
			data->result_token[dest++]=data->base_token[i];
	}

	// remove trailing spaces
	while ( dest>0 && data->result_token[dest-1]==' ' )
		dest--;

	data->result_token[dest]='\0';

	return data->result_token;
}


int next_state ( BLENDDATA * data )
{
	int i;
	int overflow = 1;
	for ( i = 0; i < NUM_BLENDED && overflow; i++ )
		if ( data->blend_flags[i] != -1 )
		{
			data->blend_flags[i] += overflow;
			overflow = data->blend_flags[i] / 2;
			data->blend_flags[i] %= 2;
		}

	return !overflow;
}


void split_string ( char * str, UT_array * tokens )
{
	int i;
	int tok_start = 0;
	int token_len = strlen ( str );
	int delta_seed = 1;
	int in_token = 1;
	TOKENINFO token;

	for ( i = 0; i < token_len; i++ )
		if ( str[i]==' ' && in_token )
		{
			strncpy ( token.token, str+tok_start, i-tok_start );
			token.token[i-tok_start] = '\0';
			token.delta = delta_seed++;
			token.source_index = (int)utarray_len(tokens);
			utarray_push_back(tokens, &token);
			in_token = 0;
		} else if ( i > 0 && str[i-1]==' ' )
		{
			tok_start = i;
			in_token = 1;
		}

	// final token
	if ( in_token )
	{
		strncpy ( token.token, str+tok_start, i-tok_start );
		token.token[i-tok_start] = '\0';
		token.delta = delta_seed;
		token.source_index = (int)utarray_len(tokens);
		utarray_push_back(tokens, &token);
	}
}


TOKENINFO * fetch_next_string ( BLENDDATA * data )
{
	TOKENINFO * token;

	if ( !data->tokens )
		return NULL;

	if ( data->result_id >= (int)(utarray_len(data->tokens)) )
	{
		utarray_free ( data->tokens );
		data->tokens = NULL;
		return NULL;
	}

	token = (TOKENINFO *)utarray_eltptr ( data->tokens, (unsigned int)data->result_id );
	data->result_id++;

	return token;
}


int token_cmp ( const void * a, const void * b )
{
	const TOKENINFO * token_a = (const TOKENINFO*)a;
	const TOKENINFO * token_b = (const TOKENINFO*)b;
	// keep initial order for blended head
	if ( token_a->delta==token_b->delta )
		return ( token_a->source_index - token_b->source_index );
	else
		return ( token_a->delta - token_b->delta );
}

DLLEXPORT int blended_ver ()
{
	return SPH_UDF_VERSION;
}

/// returns 0 on success, MUST fill error_message otherwise
DLLEXPORT int blended_init ( void ** userdata, int num_fields, const char ** field_names, const char * options, char * error_message )
{
	BLENDDATA * data = malloc(sizeof(BLENDDATA));
	memset (data, 0, sizeof(*data));
	*userdata=data;
	return 0;
}


DLLEXPORT char * blended_push_token ( void * userdata, char * token, int * extra, int * delta )
{
	BLENDDATA * data = (BLENDDATA*)userdata;
	TOKENINFO * next_token, * token1, * token2;
	int have_blended = 0;
	int have_non_blended = 0;
	int i, j;
	int num_tokens;
	char * found;
	char * str;

	*extra = 0;

	for ( i = 0; i < NUM_BLENDED; i++ )
	{
		if ( strchr ( token, all_blended[i] ) )
		{
			have_blended=1;
			data->blend_flags[i]=0;
		} else
			data->blend_flags[i]=-1;
	}

	strncpy ( data->base_token, token, MAX_TOKEN_LEN );
	data->is_blended = 0;
	data->is_blended_part = 0;
	if ( !have_blended )
		return token;

	data->base_token_len = strlen ( token );

	for ( i = 0; i < data->base_token_len; i++ )
	{
		found = strchr ( all_blended, token[i] );
		if ( found )
			data->blend_ids[i] = found-all_blended;
		else
		{
			data->blend_ids[i] = -1;
			have_non_blended = 1;
		}
	}

	if ( !have_non_blended )
		return NULL;

	utarray_new ( data->tokens, &tokeninfo_icd );
	data->result_id = 0;
	data->is_blended = 1;
	data->is_blended_part = 1;

	do
	{
		str = create_string ( data );
		split_string ( str, data->tokens );
	}
	while ( next_state ( data ) && utarray_len(data->tokens) < EXPANSION_LIMIT );

	utarray_sort ( data->tokens, token_cmp );

	// fixup deltas
	num_tokens = (int)(utarray_len(data->tokens));
	for ( i = 0; i < num_tokens; i++ )
	{
		token1 = (TOKENINFO *)utarray_eltptr ( data->tokens, (unsigned int)i );
		if ( token1->delta )
			for ( j = i+1; j < num_tokens; j++ )
			{
				token2 = (TOKENINFO *)utarray_eltptr ( data->tokens, (unsigned int)j );
				token2->delta -= token1->delta;
			}
	}

	*extra = 1;

	next_token = fetch_next_string ( data );
	if ( !next_token )
		return NULL;

	*delta = next_token->delta;

	return next_token->token;
}

/// get extra pending tokens
DLLEXPORT char * blended_get_extra_token ( void * userdata, int * delta )
{
	BLENDDATA * data = (BLENDDATA*)userdata;

	TOKENINFO * next_token = fetch_next_string ( data );
	data->is_blended = 0;
	data->is_blended_part = 0;
	if ( !next_token )
		return NULL;

	*delta = next_token->delta;
	data->is_blended_part = 1;
	return next_token->token;
}


/// final cleanup
DLLEXPORT void blended_deinit ( void * userdata )
{
	free ( userdata );
}


/// get token blended flag
DLLEXPORT int blended_is_blended ( void * userdata )
{
	BLENDDATA * data = (BLENDDATA*)userdata;
	return data->is_blended;
}

/// get token blended part flag
DLLEXPORT int blended_is_blended_part ( void * userdata )
{
	BLENDDATA * data = (BLENDDATA*)userdata;
	return data->is_blended_part;
}


//////////////////////////////////////////////////////////////////////////
/// query time token_filter

typedef struct blendparts
{
	char 		lc[BLENDED_CHAR_END/8+1];
	int			delta;
} BLENDPARTS;

void bit_set ( int i, char * data )
{
	if ( i>=BLENDED_CHAR_END )
		return;

	char flag = 1;
	flag = flag << ( i % 8 );
	data [ i/8 ] |= flag;
}

int bit_get ( int i, char * data )
{
	if ( i>=BLENDED_CHAR_END )
		return 0;

	char flag = 1;
	flag = flag << ( i % 8 );
	return ( data [ i / 8 ] & flag );
}

DLLEXPORT int query_ver()
{
	return SPH_UDF_VERSION;
}

/// returns 0 on success, MUST fill error_message otherwise
DLLEXPORT int query_init ( void ** userdata, int num_fields, const char ** field_names, const char * options, char * error_message )
{
	int i;
	BLENDPARTS * data = malloc(sizeof(BLENDPARTS));
	memset (data, 0, sizeof(*data));

	for ( i=0; i<NUM_BLENDED; i++ )
	{
		bit_set ( all_blended[i], data->lc );
	}

	*userdata=data;

	return 0;
}


DLLEXPORT char * query_push_token ( void * userdata, char * token, int * delta, const char * raw_token_start, int raw_token_len )
{
	char * blended_end = 0;
	char * s = 0;
	int blend_parts = 0;
	BLENDPARTS * data = (BLENDPARTS *)userdata;

	*delta = data->delta;
	data->delta = 0;

	if ( !token || !*token || !*(token+1) )
		return token;

	s = token;

	// FIXME!!! check escaped specials

	// skip head blended chars
	while ( *s && bit_get ( *s, data->lc ) )
		s++;

	while ( *s )
	{
		// got blended
		if ( bit_get ( *s, data->lc ) )
		{
			// has non empty blended part
			blend_parts++;

			// skip all blended chars
			s++;
			while ( *s && bit_get ( *s, data->lc ) )
				s++;

			blended_end = s;
			if ( !*s )
				break;
		}
		s++;
	}

	if ( blended_end!=0 && blended_end!=s )
		blend_parts++;

	data->delta = blend_parts;

	return token;
}

DLLEXPORT void query_deinit ( void * userdata )
{
	free ( userdata );
}
